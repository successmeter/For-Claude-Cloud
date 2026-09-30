<?php
// api/app/Strategy/Insights/InsightEngine.php
namespace App\Strategy\Insights;

use App\Services\Tenancy\TenantContext;
use App\Strategy\Insights\Rules\AnomalyDay;
use App\Strategy\Insights\Rules\BestWorstWeekday;
use App\Strategy\Insights\Rules\DataGap;
use App\Strategy\Insights\Rules\Trend28dVsPrior;
use App\Strategy\Insights\Rules\Trend28dYoy;
use App\Strategy\Insights\Rules\WeeklyStreak;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Runs the own-history rules over daily_venue_metrics and stores one insights row per venue and
 * as_of date (Plan C design §6). Findings are numbered f1..fn in rule order, and hashed over
 * canonical JSON so Phase 4's AI cache can key on them.
 */
class InsightEngine
{
    /** Rule order is the findings order. */
    private const RULES = [Trend28dYoy::class, Trend28dVsPrior::class, WeeklyStreak::class, AnomalyDay::class, BestWorstWeekday::class, DataGap::class];

    /** Enough history for last year's windows and the weekday search. */
    private const HISTORY_DAYS = 364 + 27 + 7 * 27;

    /** @return array|null the stored document; null when the venue has no data */
    public function generate(string $venueId, ?CarbonImmutable $asOf = null): ?array
    {
        if (TenantContext::current() === null) {
            throw new \LogicException('InsightEngine runs inside tenant context.');
        }

        $asOf ??= ($latest = DB::table('daily_venue_metrics')->where('venue_id', $venueId)->max('business_date'))
            ? CarbonImmutable::parse($latest) : null;
        if ($asOf === null) {
            return null;
        }

        $revenue = DB::table('daily_venue_metrics')->where('venue_id', $venueId)
            ->whereBetween('business_date', [$asOf->subDays(self::HISTORY_DAYS)->toDateString(), $asOf->toDateString()])
            ->pluck('revenue_cents', 'business_date')->map(fn ($c) => (int) $c)->all();
        $history = new VenueHistory($asOf, $revenue);

        $findings = [];
        foreach (self::RULES as $rule) {
            foreach ((new $rule(config('insights')))->findings($history) as $finding) {
                $findings[] = ['id' => 'f'.(count($findings) + 1)] + $finding->toArray();
            }
        }

        // Keys sorted like the hash input: jsonb does not keep insertion order, so stored and
        // returned documents only match if both are sorted.
        $findings = self::sortKeys($findings);
        $document = [
            'as_of' => $asOf->toDateString(),
            'rules_version' => (int) config('insights.rules_version'),
            'findings_hash' => hash('sha256', self::canonical($findings)),
            'findings' => $findings,
        ];

        DB::statement(<<<'SQL'
            INSERT INTO insights (id, org_id, venue_id, as_of, findings, findings_hash, rules_version, created_at, updated_at)
            SELECT :id, org_id, id, :as_of, :findings::jsonb, :hash, :version, now(), now() FROM venues WHERE id = :venue
            ON CONFLICT (venue_id, as_of) DO UPDATE SET
                findings = EXCLUDED.findings, findings_hash = EXCLUDED.findings_hash,
                rules_version = EXCLUDED.rules_version, updated_at = now()
        SQL, [
            'id' => (string) Str::uuid(), 'as_of' => $document['as_of'], 'venue' => $venueId,
            'findings' => json_encode($findings, JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
            'hash' => $document['findings_hash'], 'version' => $document['rules_version'],
        ]);

        return $document;
    }

    /** The latest stored document, or an empty one. */
    public function latest(string $venueId): array
    {
        $row = DB::table('insights')->where('venue_id', $venueId)->orderByDesc('as_of')->first();
        if ($row === null) {
            return ['as_of' => null, 'rules_version' => null, 'findings_hash' => null, 'findings' => []];
        }

        return [
            'as_of' => $row->as_of,
            'rules_version' => (int) $row->rules_version,
            'findings_hash' => $row->findings_hash,
            'findings' => self::sortKeys(json_decode($row->findings, true)),
        ];
    }

    /** Sorted object keys, lists kept in order, no escaping of slashes, floats keep their ".0". */
    public static function canonical(array $value): string
    {
        return json_encode(self::sortKeys($value), JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }
}

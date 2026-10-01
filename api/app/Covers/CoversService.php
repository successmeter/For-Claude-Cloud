<?php
// api/app/Covers/CoversService.php
namespace App\Covers;

use App\Bench\RecomputeVenue;
use App\Models\Venue;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The one way covers are written (Plan E design §2.1a, §2.4): the venue's own figure (`manual` in the
 * app, `upload` from a file) always wins; a booking feed fills only days the venue has not set, and
 * clears only its own figures. Every change is kept in daily_covers_revisions; metrics follow.
 */
class CoversService
{
    public const SOURCES = ['manual', 'upload', 'booking'];

    public function __construct(private RecomputeVenue $recompute) {}

    /**
     * @param  array<string, int|null>  $days  business date => covers (null clears the day)
     * @return list<string> the dates that changed
     */
    public function save(Venue $venue, array $days, string $source, ?int $userId): array
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new \InvalidArgumentException("Unknown covers source {$source}.");
        }
        if (TenantContext::current() === null) {
            throw new \LogicException('CoversService runs inside tenant context.');
        }

        return DB::transaction(function () use ($venue, $days, $source, $userId) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['covers:'.$venue->id]);
            $current = DB::table('daily_covers')->where('venue_id', $venue->id)->whereIn('business_date', array_keys($days))
                ->lockForUpdate()->get()->keyBy('business_date');

            $changed = [];
            foreach ($days as $date => $covers) {
                $old = $current->get($date);
                if ($source === 'booking' && $old !== null && $old->source !== 'booking') {
                    continue;
                }
                if ($old === null && $covers === null) {
                    continue;
                }
                if ($old !== null && $covers !== null && (int) $old->covers === $covers && $old->source === $source) {
                    continue;
                }

                if ($covers === null) {
                    DB::table('daily_covers')->where('venue_id', $venue->id)->where('business_date', $date)->delete();
                } else {
                    DB::table('daily_covers')->upsert([
                        'org_id' => $venue->org_id, 'venue_id' => $venue->id, 'business_date' => $date, 'covers' => $covers,
                        'source' => $source, 'updated_by' => $userId, 'updated_at' => now(),
                    ], ['venue_id', 'business_date'], ['covers', 'source', 'updated_by', 'updated_at']);
                }
                DB::table('daily_covers_revisions')->insert([
                    'org_id' => $venue->org_id, 'venue_id' => $venue->id, 'business_date' => $date,
                    'old_covers' => $old?->covers, 'old_source' => $old?->source,
                    'new_covers' => $covers, 'new_source' => $covers === null ? null : $source,
                    'changed_by' => $userId, 'changed_at' => now(),
                ]);
                $changed[] = $date;
            }

            sort($changed);
            if ($changed !== []) {
                $this->recompute->run($venue->id, CarbonImmutable::parse($changed[0]));
            }

            return $changed;
        });
    }

    /** @return list<array{date: string, covers: int|null, source: string|null}> every day from $from to $to */
    public function days(Venue $venue, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $stored = DB::table('daily_covers')->where('venue_id', $venue->id)
            ->whereBetween('business_date', [$from->toDateString(), $to->toDateString()])->get()->keyBy('business_date');

        $days = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $row = $stored->get($d->toDateString());
            $days[] = ['date' => $d->toDateString(), 'covers' => $row ? (int) $row->covers : null, 'source' => $row?->source];
        }

        return $days;
    }
}

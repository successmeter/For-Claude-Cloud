<?php
// api/app/Hub/Http/CompetitorSetPayload.php
namespace App\Hub\Http;

use App\Hub\Models\CompetitorSet;
use App\Hub\Models\CompetitorSetMember;
use Illuminate\Support\Collection;

/**
 * Contract v1 shapes for competitor sets (schemas competitor-set, competitor-set-list). Kept to
 * exactly the schema's fields: the schemas are closed, and members must never carry matching
 * data such as market_id.
 */
final class CompetitorSetPayload
{
    public static function summary(CompetitorSet $set): array
    {
        return [
            'id' => $set->id,
            'name' => $set->name,
            'tools' => array_values($set->tools),
            'version' => $set->version,
            'activated_at' => $set->activated_at?->toIso8601ZuluString(),
            'composition_locked_until' => $set->composition_locked_until?->toIso8601ZuluString(),
        ];
    }

    public static function full(CompetitorSet $set): array
    {
        return self::summary($set) + [
            'members' => $set->members->map(fn (CompetitorSetMember $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'website_url' => $m->website_url,
                'location_text' => $m->location_text,
                'cuisine' => $m->cuisine,
            ])->values()->all(),
        ];
    }

    public static function setEtag(CompetitorSet $set): string
    {
        return 'W/"'.$set->version.'"';
    }

    /** Changes whenever any listed set is added, removed or changed. */
    public static function listEtag(Collection $sets): string
    {
        $parts = $sets->map(fn (CompetitorSet $s) => $s->id.':'.$s->version)->sort()->implode(',');

        return 'W/"'.sha1($parts).'"';
    }
}

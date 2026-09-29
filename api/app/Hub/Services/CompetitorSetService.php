<?php
// api/app/Hub/Services/CompetitorSetService.php
namespace App\Hub\Services;

use App\Hub\Events\HubEvents;
use App\Hub\Exceptions\HubProblem;
use App\Hub\Models\CompetitorSet;
use App\Hub\Models\CompetitorSetMember;
use App\Models\User;
use App\Services\Audit\AuditLogger;

/**
 * Every competitor-set write goes through here (design §4.5): optimistic concurrency on the set's
 * version, audit entries without names, the composition lock, and an outbox event per change.
 *
 * Callers run inside the request's tenant transaction (the `tenant` middleware), so RLS scopes all
 * of this to one org and a thrown HubProblem rolls the whole write back.
 */
class CompetitorSetService
{
    public function __construct(private AuditLogger $audit, private HubEvents $events) {}

    public function create(string $orgId, string $name, array $tools, User $by): CompetitorSet
    {
        $set = CompetitorSet::create(['org_id' => $orgId, 'name' => $name, 'tools' => $tools, 'created_by' => $by->id]);
        $this->audit->record('competitor_set.created', 'competitor_set', $set->id, $orgId, ['tools' => $tools]);
        $this->changed($set);

        return $set->refresh();
    }

    public function update(CompetitorSet $set, int $expectedVersion, array $changes): CompetitorSet
    {
        // Taking the set away from the Web tool is a removal from that tool's point of view.
        if (isset($changes['tools']) && $set->isVisibleTo('web') && ! in_array('web', $changes['tools'], true)) {
            $this->assertNotLocked($set);
        }
        $this->bumpVersion($set, $expectedVersion);
        $toolsBefore = $set->tools;
        $set->fill(array_intersect_key($changes, array_flip(['name', 'tools'])))->save();
        $this->audit->record('competitor_set.updated', 'competitor_set', $set->id, $set->org_id, ['fields' => array_keys($changes)]);
        // A tool that just lost the set must hear about it too, to drop its cached copy.
        $this->changed($set, $toolsBefore);

        return $set->refresh();
    }

    public function delete(CompetitorSet $set): void
    {
        if ($set->isVisibleTo('web')) {
            $this->assertNotLocked($set);
        }
        $set->delete();
        $this->audit->record('competitor_set.deleted', 'competitor_set', $set->id, $set->org_id);
        $this->changed($set);
    }

    public function addMember(CompetitorSet $set, int $expectedVersion, array $data): CompetitorSet
    {
        $this->bumpVersion($set, $expectedVersion);
        $member = CompetitorSetMember::create(['org_id' => $set->org_id, 'set_id' => $set->id] + $data);
        $this->audit->record('competitor_set.member_added', 'competitor_set', $set->id, $set->org_id, ['member_id' => $member->id]);
        $this->changed($set);

        return $set->refresh();
    }

    public function updateMember(CompetitorSet $set, int $expectedVersion, CompetitorSetMember $member, array $data): CompetitorSet
    {
        $this->bumpVersion($set, $expectedVersion);
        $member->fill($data)->save();
        $this->audit->record('competitor_set.member_updated', 'competitor_set', $set->id, $set->org_id, ['member_id' => $member->id, 'fields' => array_keys($data)]);
        $this->changed($set);

        return $set->refresh();
    }

    public function removeMember(CompetitorSet $set, int $expectedVersion, CompetitorSetMember $member): CompetitorSet
    {
        $locks = $set->isVisibleTo('web') && $set->activated_at !== null;
        if ($locks) {
            $this->assertNotLocked($set);
        }
        $this->bumpVersion($set, $expectedVersion);
        $member->forceFill(['removed_at' => now()])->save();
        if ($locks) {
            $set->forceFill(['composition_locked_until' => now()->addDays(config('hub.composition_lock_days'))])->save();
        }
        $this->audit->record('competitor_set.member_removed', 'competitor_set', $set->id, $set->org_id, ['member_id' => $member->id]);
        $this->changed($set);

        return $set->refresh();
    }

    /**
     * Records that a consumer served a benchmark from the set (design §4.7). Idempotent; from then
     * on, removals on a web-visible set start the composition lock. Not a content change, so the
     * version is not bumped (a concurrent editor's If-Match stays valid).
     */
    public function activate(CompetitorSet $set): CompetitorSet
    {
        if ($set->activated_at === null) {
            $set->forceFill(['activated_at' => now()])->save();
            $this->audit->record('competitor_set.activated', 'competitor_set', $set->id, $set->org_id);
        }

        return $set;
    }

    /** Tells every tool that can (or until now could) see the set. */
    private function changed(CompetitorSet $set, array $alsoTools = []): void
    {
        $this->events->record(HubEvents::COMPETITOR_SET_CHANGED, $set->org_id, $set->id, array_merge($set->tools, $alsoTools));
    }

    /**
     * Design §4.7: after a web-visible set has served a benchmark, one removal locks further
     * removals, so before/after averages cannot be differenced to expose one competitor.
     */
    private function assertNotLocked(CompetitorSet $set): void
    {
        $until = $set->composition_locked_until;
        if ($until !== null && $until->isFuture()) {
            throw new HubProblem(409, 'composition_locked', 'Members cannot be removed from this set yet.', [
                'locked_until' => $until->toIso8601ZuluString(),
            ]);
        }
    }

    /**
     * Compare-and-increment in one statement, so two concurrent writers holding the same version
     * cannot both succeed.
     */
    private function bumpVersion(CompetitorSet $set, int $expected): void
    {
        $updated = CompetitorSet::whereKey($set->id)
            ->where('version', $expected)
            ->update(['version' => $expected + 1, 'updated_at' => now()]);

        if ($updated !== 1) {
            throw HubProblem::versionMismatch();
        }
    }
}

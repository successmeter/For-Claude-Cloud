<?php
// api/app/Hub/Services/CompetitorSetService.php
namespace App\Hub\Services;

use App\Hub\Exceptions\HubProblem;
use App\Hub\Models\CompetitorSet;
use App\Hub\Models\CompetitorSetMember;
use App\Models\User;
use App\Services\Audit\AuditLogger;

/**
 * Every competitor-set write goes through here (design §4.5): optimistic concurrency on the set's
 * version, audit entries without names, and (Tasks 17-18) the composition lock and outbox events.
 *
 * Callers run inside the request's tenant transaction (the `tenant` middleware), so RLS scopes all
 * of this to one org and a thrown HubProblem rolls the whole write back.
 */
class CompetitorSetService
{
    public function __construct(private AuditLogger $audit) {}

    public function create(string $orgId, string $name, array $tools, User $by): CompetitorSet
    {
        $set = CompetitorSet::create(['org_id' => $orgId, 'name' => $name, 'tools' => $tools, 'created_by' => $by->id]);
        $this->audit->record('competitor_set.created', 'competitor_set', $set->id, $orgId, ['tools' => $tools]);

        return $set->refresh();
    }

    public function update(CompetitorSet $set, int $expectedVersion, array $changes): CompetitorSet
    {
        $this->bumpVersion($set, $expectedVersion);
        $set->fill(array_intersect_key($changes, array_flip(['name', 'tools'])))->save();
        $this->audit->record('competitor_set.updated', 'competitor_set', $set->id, $set->org_id, ['fields' => array_keys($changes)]);

        return $set->refresh();
    }

    public function delete(CompetitorSet $set): void
    {
        $set->delete();
        $this->audit->record('competitor_set.deleted', 'competitor_set', $set->id, $set->org_id);
    }

    public function addMember(CompetitorSet $set, int $expectedVersion, array $data): CompetitorSet
    {
        $this->bumpVersion($set, $expectedVersion);
        $member = CompetitorSetMember::create(['org_id' => $set->org_id, 'set_id' => $set->id] + $data);
        $this->audit->record('competitor_set.member_added', 'competitor_set', $set->id, $set->org_id, ['member_id' => $member->id]);

        return $set->refresh();
    }

    public function updateMember(CompetitorSet $set, int $expectedVersion, CompetitorSetMember $member, array $data): CompetitorSet
    {
        $this->bumpVersion($set, $expectedVersion);
        $member->fill($data)->save();
        $this->audit->record('competitor_set.member_updated', 'competitor_set', $set->id, $set->org_id, ['member_id' => $member->id, 'fields' => array_keys($data)]);

        return $set->refresh();
    }

    public function removeMember(CompetitorSet $set, int $expectedVersion, CompetitorSetMember $member): CompetitorSet
    {
        $this->bumpVersion($set, $expectedVersion);
        $member->forceFill(['removed_at' => now()])->save();
        $this->audit->record('competitor_set.member_removed', 'competitor_set', $set->id, $set->org_id, ['member_id' => $member->id]);

        return $set->refresh();
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

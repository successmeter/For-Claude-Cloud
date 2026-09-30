<?php
// api/app/Http/Controllers/TeamController.php
namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ChecksOrgRole;
use App\Hub\Http\Problem;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Invitations\InvitationIssuer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Settings -> Team (Plan D design §3.1, §4). Owners and managers see the team; owners (with MFA,
 * via `mfa.owner` on the routes) invite, revoke, change roles and remove. Owners themselves are
 * invited by staff or promoted, and an org always keeps at least one owner. Everything runs in the
 * request's tenant context, so another org's people are simply not found.
 */
class TeamController extends Controller
{
    use ChecksOrgRole;

    public const ROLES = ['owner', 'manager', 'viewer'];

    public function __construct(private AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireWriter($request);

        $members = Membership::query()
            ->join('users', 'users.id', '=', 'memberships.user_id')
            ->orderBy('users.name')
            ->get(['users.id', 'users.public_id', 'users.name', 'users.email', 'users.mfa_enabled', 'memberships.role'])
            ->map(fn ($m) => [
                'id' => $m->public_id, 'name' => $m->name, 'email' => $m->email, 'role' => $m->role,
                'mfa_enabled' => (bool) $m->mfa_enabled, 'is_you' => $m->id === $request->user()->id,
            ]);

        $invitations = Invitation::whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())
            ->orderBy('created_at')->get()->map(fn (Invitation $i) => self::presentInvitation($i));

        return response()->json(['members' => $members, 'invitations' => $invitations]);
    }

    public function invite(Request $request, InvitationIssuer $issuer): JsonResponse
    {
        $this->requireRole($request, 'owner');
        $data = $request->validate([
            'email' => ['required', 'email', 'max:254'],
            'role' => ['required', Rule::in(['manager', 'viewer'])],
        ]);
        $email = trim($data['email']);
        $lower = mb_strtolower($email);

        $isMember = Membership::join('users', 'users.id', '=', 'memberships.user_id')->whereRaw('lower(users.email) = ?', [$lower])->exists();
        if ($isMember) {
            return Problem::response(409, 'already_member', 'This person is already in the team.');
        }

        $open = Invitation::whereRaw('lower(email) = ?', [$lower])->whereNull('accepted_at')->whereNull('revoked_at')->first();
        if ($open && $open->expires_at->isFuture()) {
            return Problem::response(409, 'invitation_pending', 'This person already has an invitation. Withdraw it to send a new one.');
        }
        // An expired invitation still holds the one-open-per-email slot; close it first.
        $open?->fill(['revoked_at' => now()])->save();

        [$invitation] = $issuer->issue(Org::findOrFail($this->orgId($request)), $email, $data['role'], $request->user()->id);

        return response()->json(self::presentInvitation($invitation), 201);
    }

    public function revoke(Request $request, string $invitation): JsonResponse|Response
    {
        $this->requireRole($request, 'owner');

        $found = Str::isUuid($invitation) ? Invitation::whereKey($invitation)->whereNull('accepted_at')->whereNull('revoked_at')->first() : null;
        if (! $found) {
            return Problem::response(404, 'invitation_not_found', 'Invitation not found.');
        }

        $found->fill(['revoked_at' => now()])->save();
        $this->audit->record('invitation.revoked', 'invitation', $found->id, $found->org_id);

        return response()->noContent();
    }

    public function updateMember(Request $request, string $user): JsonResponse
    {
        $this->requireRole($request, 'owner');
        $data = $request->validate(['role' => ['required', Rule::in(self::ROLES)]]);

        $membership = $this->membership($user);
        if (! $membership) {
            return $this->memberNotFound();
        }
        if ($membership->role === 'owner' && $data['role'] !== 'owner' && $this->isLastOwner()) {
            return $this->lastOwner();
        }

        $from = $membership->role;
        if ($from !== $data['role']) {
            $membership->fill(['role' => $data['role']])->save();
            $this->audit->record('membership.role_changed', 'membership', $membership->id, $membership->org_id, ['from' => $from, 'to' => $data['role']]);
        }

        return response()->json(['id' => $user, 'role' => $membership->role]);
    }

    public function removeMember(Request $request, string $user): JsonResponse|Response
    {
        $this->requireRole($request, 'owner');

        $membership = $this->membership($user);
        if (! $membership) {
            return $this->memberNotFound();
        }
        if ($membership->role === 'owner' && $this->isLastOwner()) {
            return $this->lastOwner();
        }

        $membership->delete();
        $this->audit->record('membership.removed', 'membership', $membership->id, $membership->org_id, ['role' => $membership->role]);

        return response()->noContent();
    }

    /** The org's membership for a user's public id, locked for the rest of the request's transaction. */
    private function membership(string $publicId): ?Membership
    {
        $userId = Str::isUuid($publicId) ? User::where('public_id', $publicId)->value('id') : null;

        return $userId ? Membership::where('user_id', $userId)->lockForUpdate()->first() : null;
    }

    /** Locks the org's owner rows, so two owners can't demote each other at the same moment. */
    private function isLastOwner(): bool
    {
        return Membership::where('role', 'owner')->lockForUpdate()->get(['id'])->count() <= 1;
    }

    private function lastOwner(): JsonResponse
    {
        return Problem::response(409, 'last_owner', 'A business needs at least one owner. Make someone else an owner first.');
    }

    private function memberNotFound(): JsonResponse
    {
        return Problem::response(404, 'member_not_found', 'Team member not found.');
    }

    private static function presentInvitation(Invitation $i): array
    {
        return ['id' => $i->id, 'email' => $i->email, 'role' => $i->role, 'expires_at' => $i->expires_at->toIso8601String()];
    }
}

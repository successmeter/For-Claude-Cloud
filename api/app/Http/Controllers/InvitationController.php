<?php
// api/app/Http/Controllers/InvitationController.php
namespace App\Http\Controllers;

use App\Hub\Http\Problem;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Invitations\InvitationTokens;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * Looking up and accepting an invitation (Plan D design §3.1). The token's org id selects the
 * tenant context, so the row is found under RLS without any cross-org lookup. Every reason an
 * invitation can't be used (malformed, unknown, expired, accepted, revoked) gets the same 404.
 */
class InvitationController extends Controller
{
    public function __construct(private InvitationTokens $tokens, private AuditLogger $audit) {}

    public function show(string $token): JsonResponse
    {
        $found = $this->find($token);
        if (! $found) {
            return $this->notFound();
        }
        [$invitation, $org] = $found;

        return response()->json([
            'org' => ['name' => $org->name],
            'email' => $invitation->email,
            'role' => $invitation->role,
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $found = $this->find($token);
        if (! $found) {
            return $this->notFound();
        }
        if (! $request->hasSession()) {
            return response()->json(['message' => 'This endpoint requires a stateful (first-party) request origin.'], 400);
        }

        [$orgId, $secret] = $this->tokens->parse($token);
        $signedIn = Auth::guard('web')->user();

        // Checked before the transaction so a refusal changes nothing; the transaction re-checks.
        [$invitation] = $found;
        $existing = User::whereRaw('lower(email) = ?', [mb_strtolower($invitation->email)])->first();

        if ($signedIn && mb_strtolower($signedIn->email) !== mb_strtolower($invitation->email)) {
            return Problem::response(403, 'invitation_email_mismatch', 'This invitation is for a different email address. Sign out and open the link again.');
        }
        if (! $signedIn && $existing) {
            return Problem::response(409, 'sign_in_required', 'An account already exists for this email. Sign in, then open the link again.');
        }

        $data = $signedIn ? [] : $request->validate([
            'name' => ['required', 'string', 'max:255'],
            // Plan A's password rules (RegisterController).
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()->uncompromised()],
        ]);

        $result = TenantContext::run($orgId, function () use ($orgId, $secret, $signedIn, $data) {
            $invitation = Invitation::where('token_hash', $this->tokens->hash($secret))->lockForUpdate()->first();
            if (! $invitation || ! $invitation->isOpen()) {
                return null;
            }

            $user = $signedIn ?? User::create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
            ]);

            if (Membership::where('org_id', $orgId)->where('user_id', $user->id)->exists()) {
                return 'already_member';
            }

            Membership::create(['org_id' => $orgId, 'user_id' => $user->id, 'role' => $invitation->role]);
            $invitation->fill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();

            return [$user, $invitation];
        });

        if ($result === null) {
            return $this->notFound();
        }
        if ($result === 'already_member') {
            return Problem::response(409, 'already_member', 'You are already a member of this business.');
        }
        [$user, $invitation] = $result;

        if (! $signedIn) {
            Auth::guard('web')->login($user);
        }
        $request->session()->regenerate();

        $this->audit->record('invitation.accepted', 'invitation', $invitation->id, $orgId, ['role' => $invitation->role, 'new_user' => ! $signedIn]);

        $me = MeController::payload($user);

        return response()->json($me + [
            'mfa_setup_required' => $invitation->role === 'owner' && ! $me['user']['mfa_enabled'],
        ]);
    }

    /** @return array{0: Invitation, 1: Org}|null the open invitation and its org */
    private function find(string $token): ?array
    {
        $parsed = $this->tokens->parse($token);
        if (! $parsed) {
            return null;
        }
        [$orgId, $secret] = $parsed;

        return TenantContext::run($orgId, function () use ($orgId, $secret) {
            $invitation = Invitation::where('token_hash', $this->tokens->hash($secret))->first();
            $org = Org::find($orgId);

            return $invitation && $org && $invitation->isOpen() ? [$invitation, $org] : null;
        });
    }

    private function notFound(): JsonResponse
    {
        return Problem::response(404, 'invitation_not_found', 'This invitation link is not valid. It may have expired, been used or been withdrawn.');
    }
}

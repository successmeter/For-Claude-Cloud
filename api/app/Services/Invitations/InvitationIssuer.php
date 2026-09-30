<?php
// api/app/Services/Invitations/InvitationIssuer.php
namespace App\Services\Invitations;

use App\Mail\InvitationMail;
use App\Models\Invitation;
use App\Models\Org;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Mail;

/**
 * Creates an invitation and queues its email (Plan D design §3.1). Used by the staff command and
 * by owners inviting their team. Returns the link, which holds the only copy of the token.
 */
class InvitationIssuer
{
    public const VALID_DAYS = 7;

    public function __construct(private InvitationTokens $tokens, private AuditLogger $audit) {}

    /** @return array{0: Invitation, 1: string} [invitation, link] */
    public function issue(Org $org, string $email, string $role, ?int $invitedBy = null): array
    {
        [$token, $hash] = $this->tokens->issue($org->id);

        $invitation = TenantContext::run($org->id, function () use ($org, $email, $role, $invitedBy, $hash) {
            $invitation = Invitation::create([
                'org_id' => $org->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => $hash,
                'invited_by' => $invitedBy,
                'expires_at' => now()->addDays(self::VALID_DAYS),
            ]);
            $this->audit->record('invitation.created', 'invitation', $invitation->id, $org->id, ['role' => $role]);

            return $invitation;
        });

        $link = config('app.frontend_url').'/invite/'.$token;
        Mail::to($email)->queue((new InvitationMail(
            $org->name,
            $role,
            $link,
            $invitation->expires_at->setTimezone('Australia/Sydney')->format('j F Y'),
        ))->afterCommit());

        return [$invitation, $link];
    }
}

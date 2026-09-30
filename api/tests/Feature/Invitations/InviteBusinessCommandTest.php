<?php
// api/tests/Feature/Invitations/InviteBusinessCommandTest.php

namespace Tests\Feature\Invitations;

use App\Mail\InvitationMail;
use App\Models\AuditLogEntry;
use App\Models\Invitation;
use App\Models\Org;
use App\Services\Invitations\InvitationTokens;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class InviteBusinessCommandTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_creates_the_business_and_an_owner_invitation_and_emails_the_link(): void
    {
        Mail::fake();
        config(['app.frontend_url' => 'https://app.example.test']);

        $this->assertSame(0, Artisan::call('hub:invite-business', ['name' => 'Oxford St Cafe', 'email' => 'owner@example.com']));
        $output = Artisan::output();

        preg_match_all('#https://app\.example\.test/invite/(\S+)#', $output, $m);
        $this->assertCount(1, $m[1], 'the link is printed once');
        $token = $m[1][0];
        [$orgId, $secret] = app(InvitationTokens::class)->parse($token);

        // orgs is under RLS: read it as that org.
        $org = TenantContext::run($orgId, fn () => Org::sole());
        $this->assertSame('Oxford St Cafe', $org->name);

        $invitation = TenantContext::run($org->id, fn () => Invitation::sole());
        $this->assertSame('owner@example.com', $invitation->email);
        $this->assertSame('owner', $invitation->role);
        $this->assertNull($invitation->invited_by);
        $this->assertSame(hash('sha256', $secret), $invitation->token_hash);
        $this->assertTrue($invitation->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));

        Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) use ($token) {
            return $mail->hasTo('owner@example.com') && $mail->orgName === 'Oxford St Cafe'
                && $mail->role === 'owner' && str_ends_with($mail->link, "/invite/{$token}");
        });

        $audit = AuditLogEntry::orderBy('created_at')->get(['action', 'actor_id', 'org_id'])->toArray();
        $this->assertEqualsCanonicalizing(
            [['action' => 'org.created', 'actor_id' => null, 'org_id' => $org->id], ['action' => 'invitation.created', 'actor_id' => null, 'org_id' => $org->id]],
            $audit,
        );
        $this->assertStringNotContainsString($secret, json_encode(AuditLogEntry::pluck('meta')));
    }

    public function test_the_mail_shows_the_org_role_expiry_and_link(): void
    {
        $mail = new InvitationMail('Oxford St Cafe', 'owner', 'https://app.example.test/invite/abc', '7 October 2026');

        $mail->assertSeeInHtml('Oxford St Cafe');
        $mail->assertSeeInHtml('an owner');
        $mail->assertSeeInHtml('7 October 2026');
        $mail->assertSeeInHtml('https://app.example.test/invite/abc');
        $mail->assertSeeInText('https://app.example.test/invite/abc');
        $mail->assertHasSubject("You're invited to Oxford St Cafe on Success Meter");
    }

    public function test_refuses_an_invalid_email_or_empty_name(): void
    {
        Mail::fake();

        $this->artisan('hub:invite-business', ['name' => 'Cafe', 'email' => 'not-an-email'])->assertFailed();
        $this->artisan('hub:invite-business', ['name' => '  ', 'email' => 'owner@example.com'])->assertFailed();

        $this->assertSame(0, DB::selectOne('SELECT count(*) AS n FROM audit_log')->n, 'nothing was created');
        Mail::assertNothingQueued();
    }
}

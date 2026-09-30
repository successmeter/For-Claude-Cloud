<?php
// api/tests/Feature/Team/TeamApiTest.php

namespace Tests\Feature\Team;

use App\Mail\InvitationMail;
use App\Models\AuditLogEntry;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SpaRequests;
use Tests\TestCase;

/** Plan D Task 5: Settings -> Team. */
class TeamApiTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SpaRequests;

    private Org $org;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->org = $this->org('Oxford St Cafe');
        $this->owner = $this->member($this->org, 'owner');
        $this->owner->forceFill(['mfa_enabled' => true])->save();
    }

    private function as(User $user, string $method, string $uri, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->spa($user, $this->org, $method, $uri, $data);
    }

    private function roleOf(User $user): ?string
    {
        return $this->inTenant($this->org, fn () => Membership::where('user_id', $user->id)->value('role'));
    }

    public function test_owners_and_managers_see_members_and_open_invitations(): void
    {
        $manager = $this->member($this->org, 'manager');
        $this->as($this->owner, 'POST', '/api/team/invitations', ['email' => 'new@example.com', 'role' => 'viewer'])->assertCreated();
        $this->inTenant($this->org, fn () => Invitation::create([
            'org_id' => $this->org->id, 'email' => 'old@example.com', 'role' => 'viewer', 'token_hash' => 'x', 'expires_at' => now()->subDay(),
        ]));

        $response = $this->as($manager, 'GET', '/api/team')->assertOk();

        $this->assertEqualsCanonicalizing(
            [[$this->owner->refresh()->public_id, 'owner', true, false], [$manager->refresh()->public_id, 'manager', false, true]],
            collect($response->json('members'))->map(fn ($m) => [$m['id'], $m['role'], $m['mfa_enabled'], $m['is_you']])->all(),
        );
        $this->assertSame(['new@example.com'], array_column($response->json('invitations'), 'email'), 'expired invitations are not listed');
        $this->assertArrayNotHasKey('token_hash', $response->json('invitations.0'));
    }

    public function test_viewers_cannot_see_the_team(): void
    {
        $this->as($this->member($this->org, 'viewer'), 'GET', '/api/team')->assertForbidden();
    }

    public function test_only_owners_change_the_team(): void
    {
        $manager = $this->member($this->org, 'manager');
        $viewer = $this->member($this->org, 'viewer');
        $id = $viewer->refresh()->public_id;

        foreach ([$manager, $viewer] as $user) {
            $this->as($user, 'POST', '/api/team/invitations', ['email' => 'x@example.com', 'role' => 'viewer'])->assertForbidden();
            $this->as($user, 'PATCH', "/api/team/members/{$id}", ['role' => 'manager'])->assertForbidden();
            $this->as($user, 'DELETE', "/api/team/members/{$id}")->assertForbidden();
            $this->as($user, 'DELETE', '/api/team/invitations/'.Str::uuid())->assertForbidden();
        }
        $this->assertSame('viewer', $this->roleOf($viewer));
    }

    public function test_owner_writes_need_mfa(): void
    {
        $this->owner->forceFill(['mfa_enabled' => false])->save();

        $this->as($this->owner, 'POST', '/api/team/invitations', ['email' => 'x@example.com', 'role' => 'viewer'])
            ->assertForbidden()->assertJsonPath('type', 'https://hub/problems/mfa_required');
        $this->as($this->owner, 'GET', '/api/team')->assertOk();
    }

    public function test_an_owner_invites_a_manager_or_viewer_by_email(): void
    {
        $response = $this->as($this->owner, 'POST', '/api/team/invitations', ['email' => 'Jo@Example.com', 'role' => 'manager'])
            ->assertCreated()->assertJsonPath('email', 'Jo@Example.com')->assertJsonPath('role', 'manager');

        $invitation = $this->inTenant($this->org, fn () => Invitation::findOrFail($response->json('id')));
        $this->assertSame($this->owner->id, $invitation->invited_by);
        Mail::assertQueued(InvitationMail::class, fn ($mail) => $mail->hasTo('Jo@Example.com') && $mail->orgName === 'Oxford St Cafe' && $mail->role === 'manager');
        $this->assertSame([$this->owner->id, $this->org->id], [AuditLogEntry::where('action', 'invitation.created')->sole()->actor_id, AuditLogEntry::where('action', 'invitation.created')->sole()->org_id]);

        $this->as($this->owner, 'POST', '/api/team/invitations', ['email' => 'x@example.com', 'role' => 'owner'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->as($this->owner, 'POST', '/api/team/invitations', ['email' => 'nope', 'role' => 'viewer'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_members_and_pending_invitations_are_not_invited_twice(): void
    {
        $member = $this->member($this->org, 'viewer');
        $this->as($this->owner, 'POST', '/api/team/invitations', ['email' => strtoupper($member->email), 'role' => 'viewer'])
            ->assertStatus(409)->assertJsonPath('type', 'https://hub/problems/already_member');

        $this->as($this->owner, 'POST', '/api/team/invitations', ['email' => 'jo@example.com', 'role' => 'viewer'])->assertCreated();
        $this->as($this->owner, 'POST', '/api/team/invitations', ['email' => 'JO@example.com', 'role' => 'manager'])
            ->assertStatus(409)->assertJsonPath('type', 'https://hub/problems/invitation_pending');
    }

    public function test_an_expired_invitation_can_be_sent_again(): void
    {
        $old = $this->inTenant($this->org, fn () => Invitation::create([
            'org_id' => $this->org->id, 'email' => 'jo@example.com', 'role' => 'viewer', 'token_hash' => 'x', 'expires_at' => now()->subDay(),
        ]));

        $this->as($this->owner, 'POST', '/api/team/invitations', ['email' => 'jo@example.com', 'role' => 'viewer'])->assertCreated();

        $this->assertNotNull($this->inTenant($this->org, fn () => $old->refresh()->revoked_at));
    }

    public function test_an_owner_withdraws_a_pending_invitation(): void
    {
        $id = $this->as($this->owner, 'POST', '/api/team/invitations', ['email' => 'jo@example.com', 'role' => 'viewer'])->json('id');

        $this->as($this->owner, 'DELETE', "/api/team/invitations/{$id}")->assertNoContent();
        $this->as($this->owner, 'DELETE', "/api/team/invitations/{$id}")->assertNotFound();
        $this->as($this->owner, 'DELETE', '/api/team/invitations/not-a-uuid')->assertNotFound();

        $this->assertNotNull($this->inTenant($this->org, fn () => Invitation::findOrFail($id)->revoked_at));
        $this->assertSame(1, AuditLogEntry::where('action', 'invitation.revoked')->where('entity_id', $id)->count());
        $this->as($this->owner, 'GET', '/api/team')->assertJsonPath('invitations', []);
    }

    public function test_an_owner_changes_roles(): void
    {
        $viewer = $this->member($this->org, 'viewer');

        $this->as($this->owner, 'PATCH', '/api/team/members/'.$viewer->refresh()->public_id, ['role' => 'manager'])
            ->assertOk()->assertExactJson(['id' => $viewer->public_id, 'role' => 'manager']);

        $this->assertSame('manager', $this->roleOf($viewer));
        $this->assertEqualsCanonicalizing(['from' => 'viewer', 'to' => 'manager'], AuditLogEntry::where('action', 'membership.role_changed')->sole()->meta);
        $this->as($this->owner, 'PATCH', '/api/team/members/'.$viewer->public_id, ['role' => 'admin'])->assertUnprocessable();
    }

    public function test_the_last_owner_cannot_be_demoted_or_removed(): void
    {
        $id = $this->owner->refresh()->public_id;

        $this->as($this->owner, 'PATCH', "/api/team/members/{$id}", ['role' => 'manager'])
            ->assertStatus(409)->assertJsonPath('type', 'https://hub/problems/last_owner');
        $this->as($this->owner, 'DELETE', "/api/team/members/{$id}")
            ->assertStatus(409)->assertJsonPath('type', 'https://hub/problems/last_owner');
        $this->assertSame('owner', $this->roleOf($this->owner));

        // With a second owner, either may step down.
        $second = $this->member($this->org, 'viewer');
        $this->as($this->owner, 'PATCH', '/api/team/members/'.$second->refresh()->public_id, ['role' => 'owner'])->assertOk();
        $this->as($this->owner, 'DELETE', "/api/team/members/{$id}")->assertNoContent();
        $this->assertNull($this->roleOf($this->owner));
    }

    public function test_an_owner_removes_a_member(): void
    {
        $viewer = $this->member($this->org, 'viewer');

        $this->as($this->owner, 'DELETE', '/api/team/members/'.$viewer->refresh()->public_id)->assertNoContent();

        $this->assertNull($this->roleOf($viewer));
        $this->spa($viewer, $this->org, 'GET', '/api/venues')->assertNotFound();
        $this->assertSame(['role' => 'viewer'], AuditLogEntry::where('action', 'membership.removed')->sole()->meta);
    }

    public function test_another_orgs_people_are_not_found(): void
    {
        $other = $this->org('Other');
        $stranger = $this->member($other, 'viewer');
        $theirInvite = $this->inTenant($other, fn () => Invitation::create([
            'org_id' => $other->id, 'email' => 'x@example.com', 'role' => 'viewer', 'token_hash' => 'y', 'expires_at' => now()->addDay(),
        ]));

        $this->as($this->owner, 'PATCH', '/api/team/members/'.$stranger->refresh()->public_id, ['role' => 'owner'])->assertNotFound();
        $this->as($this->owner, 'DELETE', '/api/team/members/'.$stranger->public_id)->assertNotFound();
        $this->as($this->owner, 'DELETE', "/api/team/invitations/{$theirInvite->id}")->assertNotFound();
        $this->as($this->owner, 'DELETE', '/api/team/members/not-a-uuid')->assertNotFound();

        $this->assertSame('viewer', $this->inTenant($other, fn () => Membership::where('user_id', $stranger->id)->value('role')));
        $this->assertNull($this->inTenant($other, fn () => $theirInvite->refresh()->revoked_at));
        $this->assertStringNotContainsString($stranger->email, $this->as($this->owner, 'GET', '/api/team')->getContent());
    }
}

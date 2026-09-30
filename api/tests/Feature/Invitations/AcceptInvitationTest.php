<?php
// api/tests/Feature/Invitations/AcceptInvitationTest.php

namespace Tests\Feature\Invitations;

use App\Models\AuditLogEntry;
use App\Models\Invitation;
use App\Models\Membership;
use App\Models\Org;
use App\Models\User;
use App\Services\Invitations\InvitationTokens;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SpaRequests;
use Tests\TestCase;

/** Plan D Task 4: showing and accepting an invitation. */
class AcceptInvitationTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SpaRequests;

    private const PASSWORD = 'Correct-Horse-9-Battery';

    private const ORIGIN = ['Origin' => 'http://localhost'];

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(); // the password breach check answers "not found"
    }

    /** @return array{0: string, 1: Invitation} */
    private function invitation(Org $org, string $email = 'sam@example.com', string $role = 'manager', array $attributes = []): array
    {
        [$token, $hash] = app(InvitationTokens::class)->issue($org->id);
        $invitation = $this->inTenant($org, fn () => Invitation::create($attributes + [
            'org_id' => $org->id, 'email' => $email, 'role' => $role, 'token_hash' => $hash, 'expires_at' => now()->addDays(7),
        ]));

        return [$token, $invitation];
    }

    private function accept(string $token, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/invitations/{$token}/accept", $data, self::ORIGIN);
    }

    private function newAccount(): array
    {
        return ['name' => 'Sam', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD];
    }

    public function test_shows_the_org_email_role_and_expiry(): void
    {
        [$token, $invitation] = $this->invitation($this->org('Oxford St Cafe'));

        $this->getJson("/api/invitations/{$token}")->assertOk()->assertExactJson([
            'org' => ['name' => 'Oxford St Cafe'],
            'email' => 'sam@example.com',
            'role' => 'manager',
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    public function test_every_unusable_invitation_gets_the_same_404(): void
    {
        $org = $this->org();
        $other = $this->org('Other');
        [$good] = $this->invitation($org);
        [, $secret] = app(InvitationTokens::class)->parse($good);
        [$expired] = $this->invitation($org, 'a@example.com', 'viewer', ['expires_at' => now()->subMinute()]);
        [$accepted] = $this->invitation($org, 'b@example.com', 'viewer', ['accepted_at' => now()]);
        [$revoked] = $this->invitation($org, 'c@example.com', 'viewer', ['revoked_at' => now()]);

        $bad = ['not-a-token', Str::uuid().'.'.$secret, $other->id.'.'.$secret, $org->id.'.'.str_repeat('a', 43), $expired, $accepted, $revoked];
        $bodies = [];
        foreach ($bad as $i => $token) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"]); // stay under the rate limit
            $bodies[] = $this->getJson("/api/invitations/{$token}")->assertNotFound()->json();
            $this->accept($token, $this->newAccount())->assertNotFound()->assertJsonPath('type', 'https://hub/problems/invitation_not_found');
        }

        $this->assertCount(1, array_unique(array_map('json_encode', $bodies)), 'no oracle: one answer for every reason');
        $this->assertSame(0, User::count());
    }

    public function test_a_new_person_sets_a_name_and_password_and_is_signed_in(): void
    {
        $org = $this->org('Oxford St Cafe');
        [$token, $invitation] = $this->invitation($org, 'Sam@Example.com', 'owner');

        $response = $this->accept($token, $this->newAccount())->assertOk();

        $user = User::sole();
        $this->assertSame('Sam@Example.com', $user->email, 'the account uses the invited address');
        $this->assertSame('Sam', $user->name);
        $response->assertExactJson([
            'user' => ['id' => $user->refresh()->public_id, 'name' => 'Sam', 'email' => 'Sam@Example.com', 'mfa_enabled' => false],
            'orgs' => [['id' => $org->id, 'name' => 'Oxford St Cafe', 'role' => 'owner']],
            'mfa_setup_required' => true,
        ]);
        $this->assertAuthenticatedAs($user, 'web');

        $invitation = $this->inTenant($org, fn () => $invitation->refresh());
        $this->assertNotNull($invitation->accepted_at);
        $this->assertSame($user->id, $invitation->accepted_by);

        $entry = AuditLogEntry::where('action', 'invitation.accepted')->sole();
        $this->assertSame([$user->id, $org->id, $invitation->id], [$entry->actor_id, $entry->org_id, $entry->entity_id]);
    }

    public function test_the_session_is_regenerated(): void
    {
        [$token] = $this->invitation($this->org());
        $this->withSession(['probe' => true]);
        $before = session()->getId();

        $this->accept($token, $this->newAccount())->assertOk();

        $this->assertNotSame($before, session()->getId());
    }

    public function test_managers_are_not_forced_into_mfa_setup(): void
    {
        [$token] = $this->invitation($this->org(), role: 'viewer');

        $this->accept($token, $this->newAccount())->assertOk()->assertJsonPath('mfa_setup_required', false)
            ->assertJsonPath('orgs.0.role', 'viewer');
    }

    public function test_an_invitation_works_once(): void
    {
        [$token] = $this->invitation($this->org());

        $this->accept($token, $this->newAccount())->assertOk();
        Auth::guard('web')->logout();

        $this->accept($token, $this->newAccount())->assertNotFound();
        $this->getJson("/api/invitations/{$token}")->assertNotFound();
    }

    public function test_new_accounts_follow_the_password_rules(): void
    {
        [$token] = $this->invitation($this->org());

        $this->accept($token, ['name' => 'Sam', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->accept($token, ['password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD])
            ->assertUnprocessable()->assertJsonValidationErrors('name');

        $this->assertSame(0, User::count());
    }

    public function test_an_existing_account_must_sign_in_first(): void
    {
        $org = $this->org();
        User::factory()->create(['email' => 'sam@example.com']);
        [$token] = $this->invitation($org, 'SAM@example.com');

        $this->accept($token, $this->newAccount())->assertStatus(409)->assertJsonPath('type', 'https://hub/problems/sign_in_required');

        $this->assertSame(0, $this->inTenant($org, fn () => Membership::count()));
        $this->assertGuest('web');
    }

    public function test_a_signed_in_user_with_the_invited_email_joins_the_org(): void
    {
        $org = $this->org('Oxford St Cafe');
        $mine = $this->org('Already mine');
        $user = $this->member($mine, 'owner');
        [$token, $invitation] = $this->invitation($org, strtoupper($user->email), 'manager');

        $this->actingAs($user)->postJson("/api/invitations/{$token}/accept", [], self::ORIGIN)->assertOk()
            ->assertJsonPath('orgs.1', ['id' => $org->id, 'name' => 'Oxford St Cafe', 'role' => 'manager'])
            ->assertJsonPath('mfa_setup_required', false);

        $this->assertSame(1, User::count());
        $this->assertSame('manager', $this->inTenant($org, fn () => Membership::where('user_id', $user->id)->sole()->role));
        $this->assertTrue(AuditLogEntry::where('action', 'invitation.accepted')->sole()->meta === ['role' => 'manager', 'new_user' => false]);
    }

    public function test_a_different_signed_in_user_is_refused(): void
    {
        $org = $this->org();
        $someone = User::factory()->create();
        [$token, $invitation] = $this->invitation($org);

        $this->actingAs($someone)->postJson("/api/invitations/{$token}/accept", [], self::ORIGIN)
            ->assertForbidden()->assertJsonPath('type', 'https://hub/problems/invitation_email_mismatch');

        $this->assertNull($this->inTenant($org, fn () => $invitation->refresh()->accepted_at));
    }

    public function test_an_existing_member_is_told_so_and_the_invitation_stays_open(): void
    {
        $org = $this->org();
        $user = $this->member($org, 'viewer');
        [$token] = $this->invitation($org, $user->email, 'owner');

        $this->actingAs($user)->postJson("/api/invitations/{$token}/accept", [], self::ORIGIN)
            ->assertStatus(409)->assertJsonPath('type', 'https://hub/problems/already_member');

        $this->assertSame('viewer', $this->inTenant($org, fn () => Membership::sole()->role));
    }

    public function test_lookups_are_rate_limited_per_address(): void
    {
        foreach (range(1, 10) as $i) {
            $this->getJson('/api/invitations/nope')->assertNotFound();
        }
        $this->getJson('/api/invitations/nope')->assertTooManyRequests();
        $this->accept('nope')->assertTooManyRequests();
    }
}

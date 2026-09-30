<?php
// api/tests/Feature/Invitations/InvitationsTableTest.php

namespace Tests\Feature\Invitations;

use App\Models\Invitation;
use App\Services\Invitations\InvitationTokens;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class InvitationsTableTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SpaRequests;

    private function invite(string $orgId, string $email = 'sam@example.com', string $role = 'manager'): Invitation
    {
        [, $hash] = app(InvitationTokens::class)->issue($orgId);

        return TenantContext::run($orgId, fn () => Invitation::create([
            'org_id' => $orgId, 'email' => $email, 'role' => $role, 'token_hash' => $hash, 'expires_at' => now()->addDays(7),
        ]));
    }

    public function test_tokens_carry_the_org_and_store_only_a_hash(): void
    {
        $org = $this->org();
        $tokens = app(InvitationTokens::class);

        [$token, $hash] = $tokens->issue($org->id);
        [$orgId, $secret] = $tokens->parse($token);

        $this->assertSame($org->id, $orgId);
        $this->assertSame(hash('sha256', $secret), $hash);
        $this->assertStringNotContainsString($secret, $hash);
        $this->assertGreaterThanOrEqual(43, strlen($secret), '32 random bytes, base64url');
        $this->assertNotSame($token, $tokens->issue($org->id)[0]);
    }

    public function test_malformed_tokens_parse_to_nothing(): void
    {
        $tokens = app(InvitationTokens::class);
        $good = $tokens->issue((string) Str::uuid())[0];

        foreach (['', 'abc', 'not-a-uuid.'.str_repeat('a', 43), Str::uuid().'.', Str::uuid().'.short', Str::uuid().'.'.str_repeat('a', 43).'!', $good.'.extra', "\0".$good] as $bad) {
            $this->assertNull($tokens->parse($bad), json_encode($bad));
        }
    }

    public function test_invitations_are_invisible_to_other_orgs(): void
    {
        $a = $this->org('A');
        $b = $this->org('B');
        $this->invite($a->id);

        $this->assertSame(1, TenantContext::run($a->id, fn () => Invitation::count()));
        $this->assertSame(0, TenantContext::run($b->id, fn () => Invitation::count()));
    }

    public function test_one_open_invitation_per_email_per_org_case_insensitively(): void
    {
        $org = $this->org();
        $this->invite($org->id, 'Sam@Example.com');

        $this->expectException(QueryException::class);
        $this->invite($org->id, 'sam@example.com');
    }

    public function test_a_revoked_invitation_does_not_block_a_new_one(): void
    {
        $org = $this->org();
        $first = $this->invite($org->id);
        TenantContext::run($org->id, fn () => $first->update(['revoked_at' => now()]));

        $this->invite($org->id);

        $this->assertSame(2, TenantContext::run($org->id, fn () => Invitation::count()));
    }

    public function test_roles_are_checked_and_rows_are_never_deleted(): void
    {
        $org = $this->org();
        try {
            $this->invite($org->id, role: 'admin');
            $this->fail('accepted role admin');
        } catch (QueryException) {
        }

        $invitation = $this->invite($org->id);
        $this->expectException(QueryException::class);
        TenantContext::run($org->id, fn () => DB::table('invitations')->where('id', $invitation->id)->delete());
    }
}

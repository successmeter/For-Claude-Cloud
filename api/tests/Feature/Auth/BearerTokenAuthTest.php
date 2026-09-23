<?php
// api/tests/Feature/Auth/BearerTokenAuthTest.php
namespace Tests\Feature\Auth;

use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

/**
 * IMPORTANT finding #4 (final whole-branch review): the 120000 RLS migration only
 * granted app_user access to tables that existed when it ran. Sanctum's
 * personal_access_tokens table (added later, in 150102) was never granted, so any
 * request carrying an Authorization: Bearer header to a Sanctum-guarded route made
 * the guard fall through to PersonalAccessToken::findToken()'s query, which hit
 * SQLSTATE[42501] permission denied -- an unhandled QueryException surfacing as a
 * 500, not a clean 401. This proves a well-formed-but-nonexistent bearer token now
 * gets a clean 401 instead.
 */
class BearerTokenAuthTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_a_bogus_bearer_token_gets_a_clean_401_not_a_500(): void
    {
        // No pipe character, so PersonalAccessToken::findToken() takes the
        // `where('token', hash('sha256', $token))->first()` path -- a real SELECT
        // against personal_access_tokens, exactly the query that used to hit
        // "permission denied" before app_user was granted access to this table.
        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.str_repeat('a', 40),
        ])->postJson('/api/logout');

        $response->assertStatus(401);
    }

    public function test_a_bogus_bearer_token_with_an_id_prefix_gets_a_clean_401_not_a_500(): void
    {
        // The `id|token` shaped variant, taking findToken()'s other branch
        // (static::find($id) against personal_access_tokens by primary key).
        $response = $this->withHeaders([
            'Authorization' => 'Bearer 999999|'.str_repeat('b', 40),
        ])->postJson('/api/logout');

        $response->assertStatus(401);
    }
}

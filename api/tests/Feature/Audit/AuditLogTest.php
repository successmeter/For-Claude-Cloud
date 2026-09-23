<?php
// api/tests/Feature/Audit/AuditLogTest.php
namespace Tests\Feature\Audit;

use App\Models\AuditLogEntry;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshesPrivilegedDatabase;

    public function test_recording_an_event_writes_an_entry(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        app(AuditLogger::class)->record('login', 'user', (string) $user->id);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'login',
            'entity_type' => 'user',
            'entity_id' => (string) $user->id,
            'actor_id' => $user->id,
        ]);
    }

    public function test_login_and_logout_are_audited(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);

        // Origin header required -- see LoginTest for why (bootstrap/app.php gates the
        // 'api' middleware group's session/cookie/CSRF stack behind Sanctum's
        // EnsureFrontendRequestsAreStateful).
        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'correct-horse-battery-staple',
        ], ['Origin' => 'http://localhost']);
        $this->assertDatabaseHas('audit_log', ['action' => 'login', 'entity_id' => (string) $user->id]);

        // Prove actor_id is genuinely non-null on the successful login audit entry --
        // this is the specific ordering bug the corrected wiring placement fixes
        // (recording after Auth::login()+session regenerate, not before).
        $loginEntry = AuditLogEntry::where('action', 'login')
            ->where('entity_id', (string) $user->id)
            ->first();
        $this->assertNotNull($loginEntry);
        $this->assertNotNull($loginEntry->actor_id);
        $this->assertSame($user->id, $loginEntry->actor_id);

        $this->postJson('/api/logout', [], ['Origin' => 'http://localhost']);
        $this->assertDatabaseHas('audit_log', ['action' => 'logout', 'entity_id' => (string) $user->id]);
    }

    public function test_audit_log_rows_cannot_be_updated_by_the_app_role(): void
    {
        $user = User::factory()->create();
        app(AuditLogger::class)->record('login', 'user', (string) $user->id);
        $entry = AuditLogEntry::first();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::statement('update audit_log set action = ? where id = ?', ['tampered', $entry->id]);
    }

    public function test_audit_log_rows_cannot_be_deleted_by_the_app_role(): void
    {
        $user = User::factory()->create();
        app(AuditLogger::class)->record('login', 'user', (string) $user->id);
        $entry = AuditLogEntry::first();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::statement('delete from audit_log where id = ?', [$entry->id]);
    }
}

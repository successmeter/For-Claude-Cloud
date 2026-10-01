<?php
// api/app/Http/Controllers/Pos/SquareConnectionController.php
namespace App\Http\Controllers\Pos;

use App\Hub\Exceptions\HubProblem;
use App\Http\Controllers\Concerns\ChecksOrgRole;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use App\Models\User;
use App\Pos\OAuthState;
use App\Pos\Square\SquareOAuth;
use App\Services\Audit\AuditLogger;
use App\Services\Encryption\EnvelopeEncryptor;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Connecting and disconnecting Square (Plan E design §3, §6): owners with MFA only. The callback is
 * a plain browser redirect from Square: it carries no session or org header, so the sealed `state`
 * says who started it, and that person must still be an owner with MFA when Square answers.
 */
class SquareConnectionController extends Controller
{
    use ChecksOrgRole;

    public function __construct(private SquareOAuth $oauth, private OAuthState $states, private EnvelopeEncryptor $encryptor, private AuditLogger $audit) {}

    public function connect(Request $request): JsonResponse
    {
        $this->requireRole($request, 'owner');
        if (! SquareOAuth::configured()) {
            throw new HubProblem(409, 'square_not_configured', 'Square is not set up for this Hub yet.');
        }

        return response()->json([
            'authorize_url' => $this->oauth->authorizeUrl($this->states->issue('square', $this->orgId($request), $request->user()->id)),
        ]);
    }

    public function callback(Request $request): RedirectResponse
    {
        $back = fn (string $outcome) => redirect()->away(config('app.frontend_url').'/data?square='.$outcome);

        $state = $this->states->consume('square', $request->query('state'));
        if ($state === null) {
            return $back('failed');
        }
        if ($request->query('error') !== null) {
            return $back('denied');
        }
        $code = $request->query('code');
        if (! is_string($code) || $code === '' || strlen($code) > 1024) {
            return $back('failed');
        }

        try {
            TenantContext::run($state['org_id'], function () use ($state, $code) {
                $isOwner = Membership::where('org_id', $state['org_id'])->where('user_id', $state['user_id'])->where('role', 'owner')->exists();
                if (! $isOwner || ! User::find($state['user_id'])?->mfa_enabled) {
                    throw new \RuntimeException('The person who started the connection is no longer an owner with MFA.');
                }
                $this->store($state['org_id'], $state['user_id'], $this->oauth->exchange($code));
            });
        } catch (\Throwable $e) {
            // The class only: messages from Square never carry tokens, but nothing here needs them.
            Log::warning('pos.square.connect_failed', ['org_id' => $state['org_id'], 'error' => $e::class]);

            return $back('failed');
        }

        return $back('connected');
    }

    public function destroy(Request $request): Response
    {
        $this->requireRole($request, 'owner');
        $connection = DB::table('pos_connections')->where('provider', 'square')->lockForUpdate()->first();
        if ($connection === null) {
            throw HubProblem::notFound();
        }

        $revoked = $this->oauth->revoke($this->encryptor->decrypt($connection->org_id, $connection->access_token_enc));
        DB::table('pos_connections')->where('id', $connection->id)->delete();
        $this->audit->record('pos.disconnected', 'pos_connection', $connection->id, $connection->org_id, ['provider' => 'square', 'revoked' => $revoked]);

        return response()->noContent();
    }

    /** One connection per org: the same merchant keeps its row (and location links); another replaces it. */
    private function store(string $orgId, int $userId, array $grant): void
    {
        $existing = DB::table('pos_connections')->where('provider', 'square')->lockForUpdate()->first();
        if ($existing !== null && $existing->merchant_id !== $grant['merchant_id']) {
            DB::table('pos_connections')->where('id', $existing->id)->delete();
            $existing = null;
        }

        $values = [
            'merchant_id' => $grant['merchant_id'],
            'access_token_enc' => $this->encryptor->encrypt($orgId, $grant['access_token']),
            'refresh_token_enc' => $grant['refresh_token'] === null ? null : $this->encryptor->encrypt($orgId, $grant['refresh_token']),
            'token_expires_at' => $grant['expires_at'],
            'scopes' => implode(' ', SquareOAuth::SCOPES),
            'status' => 'connected',
            'consecutive_failures' => 0,
            'paused_at' => null,
            'last_error' => null,
            'connected_by' => $userId,
            'updated_at' => now(),
        ];
        $id = $existing->id ?? (string) Str::uuid();
        if ($existing === null) {
            DB::table('pos_connections')->insert(['id' => $id, 'org_id' => $orgId, 'provider' => 'square', 'created_at' => now()] + $values);
        } else {
            DB::table('pos_connections')->where('id', $id)->update($values);
        }

        $this->audit->record('pos.connected', 'pos_connection', $id, $orgId, ['provider' => 'square', 'merchant_id' => $grant['merchant_id']], $userId);
    }
}

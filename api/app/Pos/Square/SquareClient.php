<?php
// api/app/Pos/Square/SquareClient.php
namespace App\Pos\Square;

use App\Services\Encryption\EnvelopeEncryptor;
use App\Services\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Square's API for one org's connection (Plan E design §3): the access token is decrypted here only,
 * refreshed when it has under a week left, and a refused token or refresh marks the connection
 * `needs_reauth`. 429 and 5xx are retried with backoff and jitter; anything else is final.
 * Runs inside the org's tenant context.
 */
class SquareClient
{
    public const ATTEMPTS = 4;

    public const REFRESH_WITHIN_DAYS = 7;

    private ?string $accessToken = null;

    public function __construct(private object $connection, private EnvelopeEncryptor $encryptor, private SquareOAuth $oauth) {}

    public static function forOrg(): ?self
    {
        if (TenantContext::current() === null) {
            throw new \LogicException('SquareClient runs inside tenant context.');
        }
        $connection = DB::table('pos_connections')->where('provider', 'square')->first();

        return $connection === null ? null : new self($connection, app(EnvelopeEncryptor::class), app(SquareOAuth::class));
    }

    public function connectionId(): string
    {
        return $this->connection->id;
    }

    /** @return list<array{id: string, name: string, timezone: ?string, status: ?string}> */
    public function locations(): array
    {
        return array_map(fn ($l) => [
            'id' => (string) $l['id'],
            'name' => mb_substr((string) ($l['name'] ?? $l['id']), 0, 200),
            'timezone' => $l['timezone'] ?? null,
            'status' => $l['status'] ?? null,
        ], $this->request('GET', '/v2/locations')['locations'] ?? []);
    }

    /** Catalog objects of the given types, every page; deleted ones too, as old orders still name them. */
    public function catalog(array $types): \Generator
    {
        $cursor = null;
        do {
            $page = $this->request('GET', '/v2/catalog/list', array_filter(['types' => implode(',', $types), 'include_deleted_objects' => 'true', 'cursor' => $cursor]));
            yield from $page['objects'] ?? [];
            $cursor = $page['cursor'] ?? null;
        } while ($cursor !== null);
    }

    /** Completed orders closed in [$from, $to) at one location, every page. */
    public function completedOrders(string $locationId, CarbonImmutable $from, CarbonImmutable $to): \Generator
    {
        $cursor = null;
        do {
            $page = $this->request('POST', '/v2/orders/search', array_filter([
                'location_ids' => [$locationId],
                'limit' => 500,
                'cursor' => $cursor,
                'query' => [
                    'filter' => [
                        'state_filter' => ['states' => ['COMPLETED']],
                        'date_time_filter' => ['closed_at' => [
                            'start_at' => $from->utc()->toIso8601ZuluString(),
                            'end_at' => $to->utc()->toIso8601ZuluString(),
                        ]],
                    ],
                    'sort' => ['sort_field' => 'CLOSED_AT', 'sort_order' => 'ASC'],
                ],
            ]));
            yield from $page['orders'] ?? [];
            $cursor = $page['cursor'] ?? null;
        } while ($cursor !== null);
    }

    /**
     * @throws SquareAuthFailed the token was refused (the connection is now needs_reauth)
     * @throws SquareUnavailable after the last retry
     */
    public function request(string $method, string $path, array $data = []): array
    {
        $token = $this->token();
        for ($attempt = 1; ; $attempt++) {
            try {
                $pending = Http::withToken($token)->withHeaders(['Square-Version' => config('services.square.api_version')])->acceptJson()->timeout(30);
                /** @var Response $response */
                $response = $method === 'GET' ? $pending->get(SquareOAuth::baseUrl().$path, $data) : $pending->post(SquareOAuth::baseUrl().$path, $data);
            } catch (ConnectionException) {
                $response = null;
            }

            if ($response !== null && $response->successful()) {
                return $response->json() ?? [];
            }
            if ($response !== null && in_array($response->status(), [401, 403], true)) {
                $this->markNeedsReauth('Square refused the access token.');

                throw new SquareAuthFailed("Square refused the access token ({$response->status()}).");
            }
            $retryable = $response === null || $response->status() === 429 || $response->serverError();
            if (! $retryable) {
                throw new SquareRequestFailed("Square answered {$response->status()} to {$method} {$path}.");
            }
            if ($attempt >= self::ATTEMPTS) {
                throw new SquareUnavailable($response === null ? 'Square could not be reached.' : "Square answered {$response->status()}.");
            }
            // 1s, 2s, 4s, plus up to half again at random.
            $base = 1000 * (2 ** ($attempt - 1));
            Sleep::for($base + random_int(0, intdiv($base, 2)))->milliseconds();
        }
    }

    private function token(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }
        if ($this->connection->status === 'needs_reauth') {
            throw new SquareAuthFailed('The Square connection needs the owner to reconnect.');
        }

        $expires = $this->connection->token_expires_at === null ? null : CarbonImmutable::parse($this->connection->token_expires_at);
        if ($expires !== null && $expires->lt(now()->addDays(self::REFRESH_WITHIN_DAYS))) {
            $this->refresh();
        }

        return $this->accessToken ??= $this->encryptor->decrypt($this->connection->org_id, $this->connection->access_token_enc);
    }

    private function refresh(): void
    {
        if ($this->connection->refresh_token_enc === null) {
            $this->markNeedsReauth('Square access has expired.');

            throw new SquareAuthFailed('No refresh token.');
        }
        try {
            $grant = $this->oauth->refresh($this->encryptor->decrypt($this->connection->org_id, $this->connection->refresh_token_enc));
        } catch (SquareAuthFailed $e) {
            $this->markNeedsReauth('Square refused to renew access.');

            throw $e;
        }

        $values = [
            'access_token_enc' => $this->encryptor->encrypt($this->connection->org_id, $grant['access_token']),
            'token_expires_at' => $grant['expires_at'],
            'updated_at' => now(),
        ];
        if ($grant['refresh_token'] !== null) {
            $values['refresh_token_enc'] = $this->encryptor->encrypt($this->connection->org_id, $grant['refresh_token']);
        }
        DB::table('pos_connections')->where('id', $this->connection->id)->update($values);
        foreach ($values as $k => $v) {
            $this->connection->{$k} = $v;
        }
        $this->accessToken = $grant['access_token'];
    }

    private function markNeedsReauth(string $why): void
    {
        DB::table('pos_connections')->where('id', $this->connection->id)->update(['status' => 'needs_reauth', 'last_error' => $why, 'updated_at' => now()]);
        $this->connection->status = 'needs_reauth';
    }
}

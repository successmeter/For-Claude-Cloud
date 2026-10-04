<?php
// api/tests/Support/SquareFixtures.php

namespace Tests\Support;

use App\Models\Org;
use App\Services\Encryption\EnvelopeEncryptor;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A Square connection written directly (Plan E), with sandbox config and Square's sandbox base URL. */
trait SquareFixtures
{
    protected const SQUARE = 'https://connect.squareupsandbox.com';

    protected function configureSquare(): void
    {
        config([
            'services.square.application_id' => 'sandbox-sq0idb-app',
            'services.square.application_secret' => 'sandbox-sq0csb-secret',
            'services.square.environment' => 'sandbox',
            'services.square.redirect_uri' => 'https://hub.example.test/api/pos/square/callback',
        ]);
    }

    protected function connectSquare(Org $org, array $overrides = []): string
    {
        $encryptor = app(EnvelopeEncryptor::class);
        $id = (string) Str::uuid();
        TenantContext::run($org->id, fn () => DB::table('pos_connections')->insert($overrides + [
            'id' => $id, 'org_id' => $org->id, 'provider' => 'square', 'merchant_id' => 'MERCHANT1',
            'access_token_enc' => $encryptor->encrypt($org->id, 'access-1'),
            'refresh_token_enc' => $encryptor->encrypt($org->id, 'refresh-1'),
            'token_expires_at' => now()->addDays(30), 'scopes' => 'MERCHANT_PROFILE_READ ORDERS_READ ITEMS_READ',
            'status' => 'connected', 'created_at' => now(), 'updated_at' => now(),
        ]));

        return $id;
    }

    protected function linkLocation(Org $org, string $connectionId, string $locationId, string $venueId): void
    {
        TenantContext::run($org->id, fn () => DB::table('pos_location_links')->insert([
            'org_id' => $org->id, 'connection_id' => $connectionId, 'location_id' => $locationId,
            'location_name' => $locationId, 'venue_id' => $venueId, 'created_at' => now(),
        ]));
    }

    protected function squareConnection(Org $org): ?object
    {
        return TenantContext::run($org->id, fn () => DB::table('pos_connections')->first());
    }
}

<?php
// api/tests/Feature/Venues/VenueApiTest.php

namespace Tests\Feature\Venues;

use App\Models\AuditLogEntry;
use App\Models\Org;
use App\Models\Venue;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SpaRequests;
use Tests\TestCase;

class VenueApiTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SpaRequests;

    private Org $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = $this->org('Alpha');
    }

    private function body(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Leederville Cafe',
            'address' => '1 Oxford St, Leederville WA',
            'timezone' => 'Australia/Perth',
            'segment' => 'cafe',
            'cuisine' => 'Modern Australian',
            'business_day_cutoff' => '04:00',
            'gst_inclusive_default' => true,
        ];
    }

    public function test_manager_creates_and_lists_venues(): void
    {
        $manager = $this->member($this->org, 'manager');

        $created = $this->spa($manager, $this->org, 'POST', '/api/venues', $this->body())->assertCreated();

        $created->assertJson($this->body() + ['market_id' => null]);
        $this->assertNotNull($created->json('id'));
        $this->spa($manager, $this->org, 'GET', '/api/venues')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Leederville Cafe')
            ->assertJsonCount(1, 'data');
    }

    public function test_defaults_apply(): void
    {
        $owner = $this->member($this->org);

        $this->spa($owner, $this->org, 'POST', '/api/venues', ['name' => 'X', 'segment' => 'bar'])
            ->assertCreated()
            ->assertJson(['timezone' => 'Australia/Perth', 'business_day_cutoff' => '04:00', 'gst_inclusive_default' => true]);
    }

    public function test_viewer_reads_but_cannot_write(): void
    {
        $viewer = $this->member($this->org, 'viewer');
        $venue = $this->venue($this->org);

        $this->spa($viewer, $this->org, 'GET', "/api/venues/{$venue->id}")->assertOk()->assertJsonPath('name', 'Cafe');
        $this->spa($viewer, $this->org, 'POST', '/api/venues', $this->body())
            ->assertForbidden()->assertHeader('Content-Type', 'application/problem+json');
        $this->spa($viewer, $this->org, 'PATCH', "/api/venues/{$venue->id}", ['name' => 'Y'])->assertForbidden();
    }

    public function test_patch_changes_only_given_fields_and_audits_field_names(): void
    {
        $owner = $this->member($this->org);
        $venue = $this->venue($this->org, ['cuisine' => 'Thai']);

        $this->spa($owner, $this->org, 'PATCH', "/api/venues/{$venue->id}", ['name' => 'Renamed', 'business_day_cutoff' => '05:30'])
            ->assertOk()
            ->assertJson(['name' => 'Renamed', 'cuisine' => 'Thai', 'business_day_cutoff' => '05:30']);

        $entry = AuditLogEntry::where('action', 'venue.updated')->sole();
        $this->assertSame(['fields' => ['business_day_cutoff', 'name']], $entry->meta);
        $this->assertSame($this->org->id, $entry->org_id);
    }

    public function test_create_is_audited(): void
    {
        $owner = $this->member($this->org);

        $id = $this->spa($owner, $this->org, 'POST', '/api/venues', $this->body())->json('id');

        $entry = AuditLogEntry::where('action', 'venue.created')->sole();
        $this->assertSame($id, $entry->entity_id);
        $this->assertSame([], $entry->meta ?? []);
    }

    public function test_another_orgs_venue_is_not_found(): void
    {
        $owner = $this->member($this->org);
        $foreign = $this->venue($this->org('Other'));

        $this->spa($owner, $this->org, 'GET', "/api/venues/{$foreign->id}")
            ->assertNotFound()->assertHeader('Content-Type', 'application/problem+json');
        $this->spa($owner, $this->org, 'PATCH', "/api/venues/{$foreign->id}", ['name' => 'x'])->assertNotFound();
    }

    public function test_listing_shows_only_this_orgs_venues(): void
    {
        $owner = $this->member($this->org);
        $this->venue($this->org, ['name' => 'Mine']);
        $this->venue($this->org('Other'), ['name' => 'Theirs']);

        $this->assertSame(['Mine'], collect($this->spa($owner, $this->org, 'GET', '/api/venues')->json('data'))->pluck('name')->all());
    }

    public function test_validation_problems(): void
    {
        $owner = $this->member($this->org);

        foreach ([
            ['timezone' => 'Mars/Olympus'],
            ['business_day_cutoff' => '07:00'],
            ['business_day_cutoff' => '4am'],
            ['segment' => 'nightclub'],
            ['name' => ''],
        ] as $bad) {
            $this->spa($owner, $this->org, 'POST', '/api/venues', $this->body($bad))
                ->assertStatus(422)
                ->assertHeader('Content-Type', 'application/problem+json')
                ->assertJsonPath('type', 'https://hub/problems/validation_failed');
        }
        $this->assertSame(0, $this->inTenant($this->org, fn () => Venue::count()));
    }

    public function test_market_id_is_not_writable(): void
    {
        $owner = $this->member($this->org);

        $this->spa($owner, $this->org, 'POST', '/api/venues', $this->body(['market_id' => '00000000-0000-4000-8000-000000000001']))
            ->assertCreated()
            ->assertJsonPath('market_id', null);
    }
}

<?php
// api/tests/Feature/Pos/SquareLocationsTest.php

namespace Tests\Feature\Pos;

use App\Models\AuditLogEntry;
use App\Models\Org;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Concerns\RefreshesPrivilegedDatabase;
use Tests\Support\SpaRequests;
use Tests\Support\SquareFixtures;
use Tests\TestCase;

/** Plan E Task 6: linking Square locations to venues. */
class SquareLocationsTest extends TestCase
{
    use RefreshesPrivilegedDatabase, SpaRequests, SquareFixtures;

    private Org $org;

    private User $owner;

    private Venue $venue;

    private string $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureSquare();
        Http::preventStrayRequests();
        Sleep::fake();
        $this->org = $this->org();
        $this->owner = $this->member($this->org);
        $this->owner->forceFill(['mfa_enabled' => true])->save();
        $this->venue = $this->venue($this->org);
        $this->connection = $this->connectSquare($this->org);
        Http::fake([self::SQUARE.'/v2/locations' => Http::response(['locations' => [
            ['id' => 'L1', 'name' => 'Bondi', 'timezone' => 'Australia/Sydney', 'status' => 'ACTIVE'],
            ['id' => 'L2', 'name' => 'Manly', 'timezone' => 'Australia/Sydney', 'status' => 'ACTIVE'],
        ]])]);
    }

    private function link(string $location, ?string $venueId, ?User $as = null)
    {
        return $this->spa($as ?? $this->owner, $this->org, 'PUT', "/api/pos/square/locations/{$location}", ['venue_id' => $venueId]);
    }

    private function links(): array
    {
        return $this->inTenant($this->org, fn () => DB::table('pos_location_links')->pluck('venue_id', 'location_id')->all());
    }

    public function test_owners_link_and_unlink_and_managers_see_the_list(): void
    {
        $manager = $this->member($this->org, 'manager');

        $this->link('L1', $this->venue->id)->assertOk()->assertExactJson(['id' => 'L1', 'venue_id' => $this->venue->id]);
        $this->spa($manager, $this->org, 'GET', '/api/pos/square/locations')->assertOk()->assertExactJson(['data' => [
            ['id' => 'L1', 'name' => 'Bondi', 'timezone' => 'Australia/Sydney', 'status' => 'ACTIVE', 'venue_id' => $this->venue->id],
            ['id' => 'L2', 'name' => 'Manly', 'timezone' => 'Australia/Sydney', 'status' => 'ACTIVE', 'venue_id' => null],
        ]]);
        $this->spa($this->owner, $this->org, 'GET', '/api/pos')->assertJsonPath('square.locations_linked', 1)
            ->assertJsonPath('square.venue_ids', [$this->venue->id]);

        $this->link('L1', null)->assertOk()->assertExactJson(['id' => 'L1', 'venue_id' => null]);
        $this->assertSame([], $this->links());
        $this->assertSame(['pos.location_linked', 'pos.location_unlinked'], AuditLogEntry::orderBy('id')->pluck('action')->all());
    }

    public function test_role_gates(): void
    {
        $manager = $this->member($this->org, 'manager');
        $viewer = $this->member($this->org, 'viewer');

        $this->link('L1', $this->venue->id, $manager)->assertForbidden();
        $this->spa($viewer, $this->org, 'GET', '/api/pos/square/locations')->assertForbidden();
        $this->owner->forceFill(['mfa_enabled' => false])->save();
        $this->link('L1', $this->venue->id)->assertForbidden()->assertJsonPath('type', 'https://hub/problems/mfa_required');
        $this->assertSame([], $this->links());
    }

    public function test_a_venue_takes_one_location_and_a_location_one_venue(): void
    {
        $second = $this->venue($this->org, ['name' => 'Second']);
        $this->link('L1', $this->venue->id)->assertOk();

        $this->link('L2', $this->venue->id)->assertStatus(409)->assertJsonPath('type', 'https://hub/problems/venue_already_linked');
        $this->link('L1', $second->id)->assertOk();
        $this->assertSame(['L1' => $second->id], $this->links(), 'relinking a location moves it');
    }

    public function test_unknown_locations_and_other_orgs_venues_are_refused(): void
    {
        $other = $this->org('Other');
        $foreign = $this->venue($other);

        $this->link('L9', $this->venue->id)->assertNotFound();
        $this->link('L1', $foreign->id)->assertUnprocessable()->assertJsonPath('type', 'https://hub/problems/venue_not_found');
        $this->link('L1', 'not-a-uuid')->assertUnprocessable();
        $this->assertSame([], $this->links());
    }

    public function test_a_linked_location_square_no_longer_lists_stays_visible(): void
    {
        $this->linkLocation($this->org, $this->connection, 'GONE', $this->venue->id);

        $this->spa($this->owner, $this->org, 'GET', '/api/pos/square/locations')->assertOk()
            ->assertJsonPath('data.2', ['id' => 'GONE', 'name' => 'GONE', 'timezone' => null, 'status' => 'MISSING', 'venue_id' => $this->venue->id]);
    }

    public function test_square_refusing_the_token_is_reported_and_remembered(): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::preventStrayRequests();
        Http::fake([self::SQUARE.'/v2/locations' => Http::response([], 401)]);

        $this->spa($this->owner, $this->org, 'GET', '/api/pos/square/locations')
            ->assertStatus(409)->assertJsonPath('type', 'https://hub/problems/square_needs_reauth');
        $this->assertSame('needs_reauth', $this->squareConnection($this->org)->status);
        $this->spa($this->owner, $this->org, 'GET', '/api/pos')->assertJsonPath('square.status', 'needs_reauth');
    }

    public function test_without_a_connection(): void
    {
        $other = $this->org('Other');
        $owner = $this->member($other);

        $this->spa($owner, $other, 'GET', '/api/pos/square/locations')->assertNotFound();
    }
}

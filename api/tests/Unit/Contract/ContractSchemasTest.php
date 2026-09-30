<?php
// api/tests/Unit/Contract/ContractSchemasTest.php

namespace Tests\Unit\Contract;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsContract;
use Tests\TestCase;

/**
 * Each schema accepts a hand-written valid example and rejects a near-miss, so a schema that
 * accepts everything (or nothing) is caught before any endpoint test relies on it.
 */
class ContractSchemasTest extends TestCase
{
    use AssertsContract;

    private const ORG = '0192f0a4-8c1d-7a3e-9b21-3f5d2c1e4a01';

    private const SET = '0192f0a4-8c1d-7a3e-9b21-3f5d2c1e4a02';

    private const MEMBER = '0192f0a4-8c1d-7a3e-9b21-3f5d2c1e4a03';

    public static function examples(): array
    {
        $set = [
            'id' => self::SET, 'name' => 'Leederville cafes', 'tools' => ['web'], 'version' => 3,
            'activated_at' => null, 'composition_locked_until' => null,
        ];
        $member = [
            'id' => self::MEMBER, 'name' => 'Cafe A', 'website_url' => 'https://cafe-a.example',
            'location_text' => 'Leederville', 'cuisine' => null, 'added_at' => '2026-09-01T00:00:00Z',
        ];
        $removed = ['id' => self::ORG, 'added_at' => '2026-08-01T00:00:00Z', 'removed_at' => '2026-09-15T00:00:00Z'];

        return [
            'me' => ['me',
                ['sub' => self::ORG, 'email' => 'a@example.com', 'name' => 'A'],
                ['sub' => '42', 'email' => 'a@example.com', 'name' => 'A']],                  // internal id is not a uuid
            'org' => ['org',
                ['id' => self::ORG, 'name' => 'Org'],
                ['id' => self::ORG, 'name' => 'Org', 'abn' => '123']],                          // unknown field
            'org-list' => ['org-list',
                ['orgs' => [['id' => self::ORG, 'name' => 'Org', 'role' => 'owner']]],
                ['orgs' => [['id' => self::ORG, 'name' => 'Org', 'role' => 'admin']]]],        // not a role
            'tool-link' => ['tool-link',
                ['org_id' => self::ORG, 'tool' => 'web', 'external_tenant_ref' => 't-1', 'linked_at' => '2026-10-01T00:00:00Z'],
                ['org_id' => self::ORG, 'tool' => 'sms', 'external_tenant_ref' => 't-1', 'linked_at' => '2026-10-01T00:00:00Z']],
            'competitor-set' => ['competitor-set',
                $set + ['members' => [$member], 'removed_members' => [$removed]],
                $set + ['members' => [$member], 'removed_members' => [$removed + ['name' => 'Cafe B']]]], // removed members carry no names
            'competitor-set-list' => ['competitor-set-list',
                ['sets' => [$set]],
                ['sets' => [['tools' => []] + $set]]],                                          // empty tools
            'activation' => ['activation',
                ['activated_at' => '2026-10-01T00:00:00Z'],
                ['activated_at' => null]],
            'webhook-event' => ['webhook-event',
                ['id' => self::SET, 'event' => 'competitorset.changed', 'org_id' => self::ORG, 'entity_id' => self::SET, 'occurred_at' => '2026-10-01T00:00:00Z', 'contract' => 'v1'],
                ['id' => self::SET, 'event' => 'competitorset.changed', 'org_id' => self::ORG, 'entity_id' => self::SET, 'occurred_at' => '2026-10-01T00:00:00Z', 'contract' => 'v1', 'name' => 'Leederville cafes']],
            'problem' => ['problem',
                ['type' => 'https://hub/problems/composition_locked', 'title' => 'Locked', 'status' => 409, 'locked_until' => '2026-11-01T00:00:00Z'],
                ['type' => 'https://hub/problems/x', 'title' => 'OK?', 'status' => 200]],
        ];
    }

    #[DataProvider('examples')]
    public function test_schema_accepts_valid_and_rejects_invalid(string $schema, array $valid, array $invalid): void
    {
        $this->assertMatchesContract($valid, $schema);
        $this->assertNotSame([], $this->contractErrors($invalid, $schema), "{$schema} accepted an invalid payload");
    }
}

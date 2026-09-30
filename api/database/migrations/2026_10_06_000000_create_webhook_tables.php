<?php
// api/database/migrations/2026_10_06_000000_create_webhook_tables.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Signed webhooks to other tools (design §4.6, decision B7).
 *
 * webhook_endpoints: one active URL + secret per tool (platform config, not tenant data; secrets
 * are encrypted by the model). previous_secret is kept during rotation so deliveries carry both
 * signatures.
 *
 * webhook_outbox: one row per (event, tool), written in the same transaction as the change it
 * announces, so an event exists if and only if the change committed. Ids only, never names. It has
 * an org_id but no RLS: only the delivery job reads it, without tenant context (allowlisted in
 * SchemaSecurityCatalogTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE webhook_endpoints (
                id uuid PRIMARY KEY,
                tool text NOT NULL CHECK (tool IN ('web')),
                url text NOT NULL,
                secret text NOT NULL,
                previous_secret text NULL,
                active boolean NOT NULL DEFAULT true,
                created_at timestamptz NULL,
                updated_at timestamptz NULL
            )
        SQL);
        DB::statement('CREATE UNIQUE INDEX webhook_endpoints_one_active_per_tool ON webhook_endpoints (tool) WHERE active');

        DB::statement(<<<'SQL'
            CREATE TABLE webhook_outbox (
                id uuid PRIMARY KEY,
                tool text NOT NULL,
                event text NOT NULL CHECK (event IN ('org.updated', 'org.tool_linked', 'competitorset.changed')),
                org_id uuid NOT NULL,
                entity_id uuid NOT NULL,
                occurred_at timestamptz NOT NULL,
                attempts integer NOT NULL DEFAULT 0,
                next_attempt_at timestamptz NOT NULL,
                delivered_at timestamptz NULL,
                failed_at timestamptz NULL,
                last_error text NULL
            )
        SQL);
        DB::statement('CREATE INDEX webhook_outbox_due ON webhook_outbox (next_attempt_at) WHERE delivered_at IS NULL AND failed_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE webhook_outbox');
        DB::statement('DROP TABLE webhook_endpoints');
    }
};

<?php
// api/database/migrations/2026_10_08_000000_create_org_data_keys_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Per-org data keys wrapped by AWS KMS (AwsKmsDriver), kept in the database because containers
 * have no lasting disk. Only the KMS-wrapped form is stored (base64); the plaintext key exists in
 * memory only. Destroying a key clears wrapped_key (crypto-shredding), so no DELETE grant.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE org_data_keys (
                org_id uuid PRIMARY KEY REFERENCES orgs(id) ON DELETE CASCADE,
                wrapped_key text NULL,
                kms_key_id text NOT NULL,
                created_at timestamptz NOT NULL,
                destroyed_at timestamptz NULL
            )
        SQL);
        DB::statement('ALTER TABLE org_data_keys ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE org_data_keys FORCE ROW LEVEL SECURITY');
        DB::statement("CREATE POLICY org_data_keys_tenant_isolation ON org_data_keys
            USING (org_id::text = current_setting('app.current_org_id', true))
            WITH CHECK (org_id::text = current_setting('app.current_org_id', true))");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE org_data_keys');
    }
};

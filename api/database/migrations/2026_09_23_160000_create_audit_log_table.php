<?php
// api/database/migrations/2026_09_23_160000_create_audit_log_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('org_id')->nullable();
            $table->string('action');
            $table->string('entity_type');
            $table->string('entity_id');
            $table->string('ip_address')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // app_user (created in Task 3's RLS migration) may only append.
        DB::statement('GRANT SELECT, INSERT ON audit_log TO app_user');
        DB::statement('REVOKE UPDATE, DELETE ON audit_log FROM app_user');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};

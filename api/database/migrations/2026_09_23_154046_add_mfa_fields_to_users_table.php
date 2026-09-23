<?php
// api/database/migrations/2026_09_23_154046_add_mfa_fields_to_users_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable(); // encrypted via Laravel's `encrypted` cast, not EnvelopeEncryptor (this is a platform-wide secret, not org data)
            $table->boolean('mfa_enabled')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['mfa_secret', 'mfa_enabled']);
        });
    }
};

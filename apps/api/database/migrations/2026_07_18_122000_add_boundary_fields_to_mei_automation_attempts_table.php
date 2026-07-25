<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mei_automation_attempts', function (Blueprint $table): void {
            $table->timestampTz('last_synced_at')->nullable()->after('started_at');
            $table->timestampTz('submitted_at')->nullable()->after('last_synced_at');
            $table->timestampTz('sync_lost_at')->nullable()->after('submitted_at');
            $table->json('vault_artifacts')->nullable()->after('safe_metadata');
        });
    }

    public function down(): void
    {
        Schema::table('mei_automation_attempts', function (Blueprint $table): void {
            $table->dropColumn(['last_synced_at', 'submitted_at', 'sync_lost_at', 'vault_artifacts']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('communication_inboxes')) {
            return;
        }

        DB::table('communication_inboxes')
            ->where('status', 'PAIRING')
            ->update(['status' => 'CONNECTING']);
        DB::table('communication_inboxes')
            ->whereNotIn('status', ['CONNECTING', 'CONNECTED'])
            ->update(['status' => 'DISCONNECTED']);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE communication_inboxes ALTER COLUMN status SET DEFAULT 'DISCONNECTED'");
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('communication_inboxes')) {
            return;
        }

        DB::table('communication_inboxes')
            ->where('status', 'CONNECTING')
            ->update(['status' => 'PAIRING']);
        DB::table('communication_inboxes')
            ->where('status', 'DISCONNECTED')
            ->update(['status' => 'DISABLED']);
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE communication_inboxes ALTER COLUMN status SET DEFAULT 'DISABLED'");
        }
    }
};

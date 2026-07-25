<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * progress_cursor SITFIS usa hash curto (protocol:{sha16}); amplia folga de 120→64.
 * Tokens oficiais não cabem em 120 quando gravados por engano no cursor.
 * SQLite ignora comprimento de VARCHAR — no-op nos testes in-memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fiscal_monitoring_runs')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE fiscal_monitoring_runs ALTER COLUMN progress_cursor TYPE varchar(64)');

            return;
        }

        DB::statement('ALTER TABLE fiscal_monitoring_runs MODIFY progress_cursor VARCHAR(64) NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('fiscal_monitoring_runs')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE fiscal_monitoring_runs ALTER COLUMN progress_cursor TYPE varchar(120)');

            return;
        }

        DB::statement('ALTER TABLE fiscal_monitoring_runs MODIFY progress_cursor VARCHAR(120) NULL');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Amplia `competence` para caber Período variável (YYYY-MM | YYYY-Tn | YYYY).
 * C0 formalizou o domínio; C1 (recorrência trimestral/anual) exige persistência ≥ 8 chars.
 * SQLite ignora comprimento de VARCHAR — no-op nos testes in-memory.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->widen('operational_processes', 16);
        $this->widen('process_generation_batches', 16);
    }

    public function down(): void
    {
        $this->widen('operational_processes', 7);
        $this->widen('process_generation_batches', 7);
    }

    private function widen(string $table, int $length): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'competence')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(sprintf(
                'ALTER TABLE %s ALTER COLUMN competence TYPE varchar(%d)',
                $table,
                $length,
            ));

            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE %s MODIFY competence VARCHAR(%d) NOT NULL',
            $table,
            $length,
        ));
    }
};

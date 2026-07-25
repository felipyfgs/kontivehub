<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cada cliente = um CNPJ (estabelecimento único).
 * Filiais cadastram-se como clientes separados; a unicidade de negócio é o CNPJ completo
 * (já em establishments.office_id+cnpj), não a raiz de 8 dígitos.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Remove unique (office_id, root_cnpj) — várias filiais da mesma raiz podem ser clientes.
        $this->dropUniqueIfExists('clients', 'clients_office_id_root_cnpj_unique');

        Schema::table('clients', function (Blueprint $table): void {
            if (! $this->indexExists('clients', 'clients_office_id_root_cnpj_index')) {
                $table->index(['office_id', 'root_cnpj'], 'clients_office_id_root_cnpj_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            if ($this->indexExists('clients', 'clients_office_id_root_cnpj_index')) {
                $table->dropIndex('clients_office_id_root_cnpj_index');
            }
        });

        // Só recria unique se não houver duplicatas de raiz
        $dupes = DB::table('clients')
            ->select(['office_id', 'root_cnpj', DB::raw('COUNT(*) as total')])
            ->groupBy('office_id', 'root_cnpj')
            ->havingRaw('COUNT(*) > 1')
            ->count();

        if ($dupes === 0) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->unique(['office_id', 'root_cnpj']);
            });
        }
    }

    private function dropUniqueIfExists(string $table, string $index): void
    {
        if (! $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index): void {
            $blueprint->dropUnique($index);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                'SELECT 1 AS ok FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $index]
            );

            return $row !== null;
        }

        // sqlite / mysql fallback via try
        try {
            $sm = Schema::getConnection()->getDoctrineSchemaManager();
            // doctrine may be unavailable; probe via raw
        } catch (Throwable) {
            // continue
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                $name = is_object($row) ? ($row->name ?? null) : ($row['name'] ?? null);
                if ($name === $index) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
};

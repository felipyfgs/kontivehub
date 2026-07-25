<?php

use App\Support\FiscalDataModel\MigrationPrecondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chaves candidatas (office_id, id) para FKs compostas de tenancy.
 * Não remove FKs simples legadas nesta etapa.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'clients',
        'establishments',
        'dfe_documents',
        'sync_cursors',
        'channel_sync_cursors',
    ];

    public function up(): void
    {
        MigrationPrecondition::tablesExist($this->tables, 'office_composite_candidate_keys');

        foreach ($this->tables as $table) {
            MigrationPrecondition::columnExists($table, 'office_id', 'office_composite_candidate_keys');
            MigrationPrecondition::columnExists($table, 'id', 'office_composite_candidate_keys');

            $indexName = $table.'_office_id_id_unique';
            if ($this->indexExists($table, $indexName)) {
                throw new RuntimeException(
                    "Pré-condição de migration falhou [office_composite_candidate_keys]: índice já existe {$indexName}.",
                );
            }

            Schema::table($table, function ($blueprint) use ($indexName): void {
                $blueprint->unique(['office_id', 'id'], $indexName);
            });
        }

        // establishments.client deve pertencer ao mesmo office (FK composta aditiva).
        // Só cria se ainda não existir.
        if (DB::getDriverName() === 'pgsql') {
            $exists = DB::selectOne(<<<'SQL'
                SELECT 1 AS ok
                FROM pg_constraint
                WHERE conname = 'establishments_office_client_composite_fk'
                SQL);
            if ($exists === null) {
                DB::statement(<<<'SQL'
                    ALTER TABLE establishments
                    ADD CONSTRAINT establishments_office_client_composite_fk
                    FOREIGN KEY (office_id, client_id)
                    REFERENCES clients (office_id, id)
                    ON DELETE RESTRICT
                    SQL);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE establishments DROP CONSTRAINT IF EXISTS establishments_office_client_composite_fk');
        }

        foreach ($this->tables as $table) {
            $indexName = $table.'_office_id_id_unique';
            Schema::table($table, function ($blueprint) use ($indexName): void {
                $blueprint->dropUnique($indexName);
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = DB::getDriverName();
        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                'SELECT 1 AS ok FROM pg_indexes WHERE schemaname = ? AND tablename = ? AND indexname = ?',
                ['public', $table, $indexName],
            );

            return $row !== null;
        }

        // SQLite / outros: tenta via doctrine-less pragma
        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");
            foreach ($rows as $row) {
                if (($row->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
};

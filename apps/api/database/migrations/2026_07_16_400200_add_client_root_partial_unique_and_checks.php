<?php

use App\Support\FiscalDataModel\MigrationPrecondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Unicidade parcial de Cliente-raiz por (office_id, root_cnpj) e CHECK de formato.
 * Filiais legadas (matrix_client_id IS NOT NULL) ficam fora do unique até o colapso (3.7).
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationPrecondition::tableExists('clients', 'client_root_partial_unique');
        MigrationPrecondition::columnExists('clients', 'root_cnpj', 'client_root_partial_unique');
        MigrationPrecondition::columnExists('clients', 'matrix_client_id', 'client_root_partial_unique');

        if (DB::getDriverName() !== 'pgsql') {
            // SQLite de teste: unique parcial via índice único filtrado se suportado
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS clients_office_root_canonical_unique
                 ON clients (office_id, root_cnpj)
                 WHERE matrix_client_id IS NULL AND deleted_at IS NULL',
            );

            return;
        }

        // Pré-checagem: raízes canônicas duplicadas no mesmo office quebram o unique.
        $dupes = DB::select(<<<'SQL'
            SELECT office_id, root_cnpj, COUNT(*) AS cnt
            FROM clients
            WHERE matrix_client_id IS NULL AND deleted_at IS NULL
            GROUP BY office_id, root_cnpj
            HAVING COUNT(*) > 1
            LIMIT 20
            SQL);
        if ($dupes !== []) {
            $sample = collect($dupes)
                ->map(fn ($r) => sprintf('office=%s root=%s n=%s', $r->office_id, $r->root_cnpj, $r->cnt))
                ->implode('; ');
            throw new RuntimeException(
                'client_root_partial_unique: raízes canônicas duplicadas antes do índice. '
                .'Execute colapso/merge ou resolva manualmente. Amostra: '.$sample,
            );
        }

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX clients_office_root_canonical_unique
            ON clients (office_id, root_cnpj)
            WHERE matrix_client_id IS NULL AND deleted_at IS NULL
            SQL);

        // root_cnpj: 8 chars alfanuméricos maiúsculos (normalizado)
        DB::statement(<<<'SQL'
            ALTER TABLE clients
            ADD CONSTRAINT clients_root_cnpj_format_check
            CHECK (root_cnpj ~ '^[0-9A-Z]{8}$')
            SQL);

        // establishments.cnpj: 14 chars alfanuméricos
        MigrationPrecondition::tableExists('establishments', 'client_root_partial_unique');
        DB::statement(<<<'SQL'
            ALTER TABLE establishments
            ADD CONSTRAINT establishments_cnpj_format_check
            CHECK (cnpj ~ '^[0-9A-Z]{14}$')
            SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE establishments DROP CONSTRAINT IF EXISTS establishments_cnpj_format_check');
            DB::statement('ALTER TABLE clients DROP CONSTRAINT IF EXISTS clients_root_cnpj_format_check');
            DB::statement('DROP INDEX IF EXISTS clients_office_root_canonical_unique');

            return;
        }

        DB::statement('DROP INDEX IF EXISTS clients_office_root_canonical_unique');
    }
};

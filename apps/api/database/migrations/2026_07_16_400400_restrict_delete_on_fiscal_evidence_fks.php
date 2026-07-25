<?php

use App\Support\FiscalDataModel\MigrationPrecondition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Troca CASCADE por RESTRICT nas FKs de evidência fiscal prioritárias (PostgreSQL).
 * Cadastro não apaga histórico documental/ledger por cascata.
 */
return new class extends Migration
{
    /**
     * @var list<array{table: string, constraint: string, column: string, parent: string, parent_col: string}>
     */
    private array $targets = [
        [
            'table' => 'dfe_documents',
            'constraint' => 'dfe_documents_office_id_foreign',
            'column' => 'office_id',
            'parent' => 'offices',
            'parent_col' => 'id',
        ],
        [
            'table' => 'document_acquisitions',
            'constraint' => 'document_acquisitions_dfe_document_id_foreign',
            'column' => 'dfe_document_id',
            'parent' => 'dfe_documents',
            'parent_col' => 'id',
        ],
        [
            'table' => 'document_acquisitions',
            'constraint' => 'document_acquisitions_office_id_foreign',
            'column' => 'office_id',
            'parent' => 'offices',
            'parent_col' => 'id',
        ],
        [
            'table' => 'document_interests',
            'constraint' => 'document_interests_dfe_document_id_foreign',
            'column' => 'dfe_document_id',
            'parent' => 'dfe_documents',
            'parent_col' => 'id',
        ],
        [
            'table' => 'serpro_api_usage_entries',
            'constraint' => 'serpro_api_usage_entries_office_id_foreign',
            'column' => 'office_id',
            'parent' => 'offices',
            'parent_col' => 'id',
        ],
        [
            'table' => 'fiscal_snapshots',
            'constraint' => 'fiscal_snapshots_client_id_foreign',
            'column' => 'client_id',
            'parent' => 'clients',
            'parent_col' => 'id',
        ],
        [
            'table' => 'fiscal_monitoring_runs',
            'constraint' => 'fiscal_monitoring_runs_client_id_foreign',
            'column' => 'client_id',
            'parent' => 'clients',
            'parent_col' => 'id',
        ],
        [
            'table' => 'tax_guide_versions',
            'constraint' => 'tax_guide_versions_tax_guide_id_foreign',
            'column' => 'tax_guide_id',
            'parent' => 'tax_guides',
            'parent_col' => 'id',
        ],
        [
            'table' => 'nfse_notes',
            'constraint' => 'nfse_notes_dfe_document_id_foreign',
            'column' => 'dfe_document_id',
            'parent' => 'dfe_documents',
            'parent_col' => 'id',
        ],
        [
            'table' => 'nfe_documents',
            'constraint' => 'nfe_documents_dfe_document_id_foreign',
            'column' => 'dfe_document_id',
            'parent' => 'dfe_documents',
            'parent_col' => 'id',
        ],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->targets as $t) {
            MigrationPrecondition::tableExists($t['table'], 'restrict_fiscal_evidence_fks');

            $exists = DB::selectOne(
                'SELECT 1 AS ok FROM pg_constraint WHERE conname = ?',
                [$t['constraint']],
            );
            if ($exists === null) {
                // Constraint com nome diferente no ambiente — registrar e seguir.
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE %s DROP CONSTRAINT %s',
                $t['table'],
                $t['constraint'],
            ));
            DB::statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE RESTRICT',
                $t['table'],
                $t['constraint'],
                $t['column'],
                $t['parent'],
                $t['parent_col'],
            ));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->targets as $t) {
            $exists = DB::selectOne(
                'SELECT 1 AS ok FROM pg_constraint WHERE conname = ?',
                [$t['constraint']],
            );
            if ($exists === null) {
                continue;
            }

            DB::statement(sprintf(
                'ALTER TABLE %s DROP CONSTRAINT %s',
                $t['table'],
                $t['constraint'],
            ));
            DB::statement(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE CASCADE',
                $t['table'],
                $t['constraint'],
                $t['column'],
                $t['parent'],
                $t['parent_col'],
            ));
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave schema-conventions W2/W3 (parcial):
 * - Encolhe *vault_object_id* legados 40/64 → 26 após auditoria de MAX(LENGTH).
 * - Alarga colunas status subdimensionadas (< 32) para 32.
 *
 * Instantes de domínio novos continuam preferindo timestampTz (sem bulk rewrite).
 *
 * @see docs/ops/schema-conventions.md
 */
return new class extends Migration
{
    /**
     * @var list<array{0: string, 1: string}>
     */
    private array $vaultColumns = [
        ['outbound_capture_profiles', 'csc_vault_object_id'],
        ['outbound_series_cursors', 'seed_vault_object_id'],
        ['fiscal_evidence_artifacts', 'vault_object_id'],
        ['mailbox_messages', 'body_vault_object_id'],
        ['mailbox_attachments', 'vault_object_id'],
        ['tax_guide_versions', 'vault_object_id'],
        ['tax_guide_payment_confirmations', 'vault_object_id'],
        ['esocial_event_evidences', 'vault_object_id'],
        ['operational_task_evidences', 'vault_object_id'],
    ];

    /**
     * Tabelas com status típico de máquina de estado (widen se character_maximum_length < 32).
     *
     * @var list<string>
     */
    private array $statusTables = [
        'client_credentials',
        'sync_cursors',
        'sync_runs',
        'nfse_notes',
        'nfse_events',
        'exports',
        'instance_backup_runs',
        'channel_sync_cursors',
        'nfe_documents',
        'nfe_events',
        'cte_documents',
        'cte_events',
        'mdfe_documents',
        'outbound_capture_profiles',
        'outbound_series_cursors',
        'outbound_capture_runs',
        'ma_outbound_retrieval_requests',
        'office_fiscal_identities',
        'office_credentials',
        'office_autxml_enrollments',
        'office_distribution_cursors',
        'office_distribution_runs',
        'outbound_monthly_readiness',
        'office_integration_tokens',
        'office_subscriptions',
        'serpro_contracts',
        'tax_proxy_powers',
        'serpro_api_usage_reservations',
        'serpro_usage_reconciliations',
        'office_fiscal_category_links',
        'fiscal_last_update_events',
        'fiscal_monitoring_runs',
        'fiscal_pending_items',
        'dctfweb_mutation_attempts',
        'tax_installment_parcels',
        'tax_installment_payments',
        'fiscal_mutation_operations',
        'operational_exports',
        'serpro_credential_versions',
        'serpro_external_gates',
        'serpro_readiness_evidences',
        'serpro_rollout_approvals',
        'serpro_billing_cycles',
        'serpro_async_job_runs',
        'serpro_eventos_runs',
        'serpro_retention_jobs',
        'office_credential_purpose_links',
        'client_procuracao_syncs',
        'serpro_dte_canary_requests',
    ];

    public function up(): void
    {
        $this->shrinkVaultColumns();
        $this->widenStatusColumns();
    }

    public function down(): void
    {
        // Widen-only e shrink auditado: down não restaura lengths legados.
    }

    private function shrinkVaultColumns(): void
    {
        foreach ($this->vaultColumns as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $max = DB::table($table)->selectRaw("MAX(LENGTH({$column})) as m")->value('m');
            if ($max !== null && (int) $max > 26) {
                throw new RuntimeException(
                    "schema-conventions: {$table}.{$column} tem valor com length {$max} > 26; investigue antes do shrink."
                );
            }

            $this->alterStringLength($table, $column, 26);
        }
    }

    private function widenStatusColumns(): void
    {
        foreach ($this->statusTables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'status')) {
                continue;
            }

            $current = $this->columnLength($table, 'status');
            if ($current !== null && $current < 32) {
                $this->alterStringLength($table, 'status', 32);
            }
        }
    }

    private function columnLength(string $table, string $column): ?int
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            $row = DB::selectOne(
                'SELECT character_maximum_length AS len
                 FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = ?
                   AND column_name = ?',
                [$table, $column]
            );

            return $row?->len !== null ? (int) $row->len : null;
        }

        // SQLite não enforce length; retorna null (skip widen desnecessário).
        return null;
    }

    private function alterStringLength(string $table, string $column, int $length): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                sprintf(
                    'ALTER TABLE %s ALTER COLUMN %s TYPE varchar(%d)',
                    $this->quoteIdent($table),
                    $this->quoteIdent($column),
                    $length
                )
            );

            return;
        }

        // SQLite: varchar length is not enforced; create migrations already align fresh installs.
        if ($driver === 'sqlite') {
            return;
        }

        // Fallback genérico (mysql etc.)
        DB::statement(
            sprintf(
                'ALTER TABLE %s MODIFY %s VARCHAR(%d)',
                $table,
                $column,
                $length
            )
        );
    }

    private function quoteIdent(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
};

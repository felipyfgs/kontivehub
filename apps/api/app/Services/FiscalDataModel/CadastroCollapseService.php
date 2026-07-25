<?php

namespace App\Services\FiscalDataModel;

use App\Enums\CredentialStatus;
use App\Support\FiscalDataModel\BackfillResult;
use App\Support\FiscalDataModel\FiscalModelAggregates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Colapsa clientes-filial legados (matrix_client_id) no Cliente-raiz canônico.
 * Reatribui FKs de client_id, move A1 com detecção de conflito, soft-delete da filial.
 * Nunca copia vault_object_id nem material secreto — só reatribui a linha de metadados.
 */
final class CadastroCollapseService
{
    /**
     * Tabelas com client_id a remapar (ordem: cadastro primeiro, depois evidências).
     *
     * @var list<string>
     */
    private const CLIENT_FK_TABLES = [
        'establishments',
        'client_contacts',
        'client_custom_fields',
        'client_category_assignments',
        'client_tax_regime_periods',
        'client_credentials',
        'document_import_batches',
        'outbound_capture_profiles',
        'fiscal_competences',
        'fiscal_monitoring_schedules',
        'fiscal_monitoring_runs',
        'fiscal_snapshots',
        'fiscal_findings',
        'fiscal_pending_items',
        'fiscal_guide_stubs',
        'fiscal_last_update_events',
        'fiscal_mutation_operations',
        'office_fiscal_category_links',
        'cte_coverage_snapshots',
        'dctfweb_declarations',
        'dctfweb_darf_documents',
        'dctfweb_evidence_versions',
        'dctfweb_mutation_attempts',
        'mit_apuracoes',
        'esocial_event_evidences',
        'fgts_competence_statuses',
        'mailbox_contributor_states',
        'mailbox_messages',
        'mailbox_alerts',
        'tax_guides',
        'tax_installment_orders',
        'tax_installment_parcels',
        'tax_installment_payments',
        'tax_obligation_projections',
        'tax_proxy_powers',
        'serpro_api_usage_entries',
        'serpro_api_usage_reservations',
        'operational_processes',
        'process_generation_items',
    ];

    public function run(bool $dryRun = true, ?int $officeId = null): BackfillResult
    {
        $processed = 0;
        $mapped = 0;
        $skipped = 0;
        $rejected = 0;
        $ambiguous = 0;
        $rejections = [];
        $ambiguities = [];
        $lastId = null;

        $query = DB::table('clients')
            ->whereNotNull('matrix_client_id')
            ->orderBy('id');

        if ($officeId !== null) {
            $query->where('office_id', $officeId);
        }

        $branches = $query->get();

        foreach ($branches as $branch) {
            $processed++;
            $lastId = (int) $branch->id;

            $already = DB::table('fiscal_model_migration_maps')
                ->where('aggregate', FiscalModelAggregates::TENANCY_CADASTRO)
                ->where('source_table', 'clients')
                ->where('source_id', (string) $branch->id)
                ->where('status', 'MAPPED')
                ->where('notes_sanitized', 'like', 'collapse:%')
                ->first();

            if ($already !== null) {
                $skipped++;

                continue;
            }

            $root = DB::table('clients')
                ->where('id', $branch->matrix_client_id)
                ->where('office_id', $branch->office_id)
                ->first();

            if ($root === null) {
                $rejected++;
                $rejections[] = [
                    'source_table' => 'clients',
                    'source_id' => (int) $branch->id,
                    'reason' => 'root_missing_or_cross_office',
                ];
                if (! $dryRun) {
                    $this->writeMap(
                        (int) $branch->id,
                        null,
                        (int) $branch->office_id,
                        'REJECTED',
                        'collapse:root_missing',
                    );
                }

                continue;
            }

            if ($root->root_cnpj !== $branch->root_cnpj) {
                $ambiguous++;
                $ambiguities[] = [
                    'source_table' => 'clients',
                    'source_id' => (int) $branch->id,
                    'reason' => 'root_cnpj_mismatch',
                ];
                if (! $dryRun) {
                    $this->writeMap(
                        (int) $branch->id,
                        (int) $root->id,
                        (int) $branch->office_id,
                        'AMBIGUOUS',
                        'collapse:root_cnpj_mismatch',
                    );
                }

                continue;
            }

            $credConflict = $this->activeCredentialConflict((int) $branch->id, (int) $root->id);
            if ($credConflict) {
                $ambiguous++;
                $ambiguities[] = [
                    'source_table' => 'clients',
                    'source_id' => (int) $branch->id,
                    'reason' => 'active_credential_conflict',
                ];
                if (! $dryRun) {
                    $this->writeMap(
                        (int) $branch->id,
                        (int) $root->id,
                        (int) $branch->office_id,
                        'AMBIGUOUS',
                        'collapse:active_credential_conflict',
                    );
                }

                continue;
            }

            $mapped++;
            if ($dryRun) {
                continue;
            }

            try {
                DB::transaction(function () use ($branch, $root): void {
                    $this->remapClientForeignKeys((int) $branch->id, (int) $root->id, (int) $branch->office_id);
                    $this->ensureSingleMatrix((int) $root->id, (int) $branch->office_id);

                    DB::table('clients')->where('id', $branch->id)->delete();

                    $this->writeMap(
                        (int) $branch->id,
                        (int) $root->id,
                        (int) $branch->office_id,
                        'MAPPED',
                        'collapse:branch_into_root',
                    );
                });
            } catch (Throwable $e) {
                $mapped--;
                $rejected++;
                $rejections[] = [
                    'source_table' => 'clients',
                    'source_id' => (int) $branch->id,
                    'reason' => 'collapse_failed:'.class_basename($e),
                ];
                report($e);
            }
        }

        // Também mapeia raízes identity (sem colapso) se ainda não mapeadas
        $identity = $this->mapRootIdentities($dryRun, $officeId);
        $processed += $identity['processed'];
        $mapped += $identity['mapped'];
        $skipped += $identity['skipped'];

        $checkpoint = $lastId !== null ? "collapse:clients:{$lastId}" : null;
        if (! $dryRun && $checkpoint !== null) {
            DB::table('fiscal_model_backfill_checkpoints')->updateOrInsert(
                [
                    'aggregate' => FiscalModelAggregates::TENANCY_CADASTRO,
                    'cursor_key' => 'collapse_clients',
                ],
                [
                    'cursor_value' => (string) ($lastId ?? 0),
                    'office_id' => $officeId,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return new BackfillResult(
            aggregate: FiscalModelAggregates::TENANCY_CADASTRO,
            dryRun: $dryRun,
            processed: $processed,
            mapped: $mapped,
            skipped: $skipped,
            rejected: $rejected,
            ambiguous: $ambiguous,
            rejections: $rejections,
            ambiguities: $ambiguities,
            checkpoint: $checkpoint,
        );
    }

    /**
     * @return array{processed: int, mapped: int, skipped: int}
     */
    private function mapRootIdentities(bool $dryRun, ?int $officeId): array
    {
        $processed = 0;
        $mapped = 0;
        $skipped = 0;

        $query = DB::table('clients')
            ->whereNull('matrix_client_id')
            ->orderBy('id');
        if ($officeId !== null) {
            $query->where('office_id', $officeId);
        }

        foreach ($query->get() as $row) {
            $processed++;
            $existing = DB::table('fiscal_model_migration_maps')
                ->where('aggregate', FiscalModelAggregates::TENANCY_CADASTRO)
                ->where('source_table', 'clients')
                ->where('source_id', (string) $row->id)
                ->where('status', 'MAPPED')
                ->first();
            if ($existing !== null) {
                $skipped++;

                continue;
            }
            $mapped++;
            if ($dryRun) {
                continue;
            }
            $this->writeMap(
                (int) $row->id,
                (int) $row->id,
                (int) $row->office_id,
                'MAPPED',
                'identity:root_client',
            );
        }

        return compact('processed', 'mapped', 'skipped');
    }

    private function activeCredentialConflict(int $branchId, int $rootId): bool
    {
        if (! Schema::hasTable('client_credentials')) {
            return false;
        }

        $branchActive = DB::table('client_credentials')
            ->where('client_id', $branchId)
            ->where('status', CredentialStatus::Active->value)
            ->exists();
        $rootActive = DB::table('client_credentials')
            ->where('client_id', $rootId)
            ->where('status', CredentialStatus::Active->value)
            ->exists();

        return $branchActive && $rootActive;
    }

    private function remapClientForeignKeys(int $fromClientId, int $toClientId, int $officeId): void
    {
        $this->mergeClientCategoryAssignments($fromClientId, $toClientId, $officeId);

        foreach (self::CLIENT_FK_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'client_id')) {
                continue;
            }

            // Pivot possui unique por cliente/categoria e já foi mesclado sem colisão.
            if ($table === 'client_category_assignments') {
                continue;
            }

            $q = DB::table($table)->where('client_id', $fromClientId);
            if (Schema::hasColumn($table, 'office_id')) {
                $q->where('office_id', $officeId);
            }

            // Credenciais: nunca duplicar ACTIVE no destino (já barrado antes).
            if ($table === 'client_credentials') {
                $q->update([
                    'client_id' => $toClientId,
                    'updated_at' => now(),
                ]);

                continue;
            }

            $update = ['client_id' => $toClientId];
            if (Schema::hasColumn($table, 'updated_at')) {
                $update['updated_at'] = now();
            }
            $q->update($update);
        }

        // Filiais que apontavam para o branch passam a apontar para a raiz
        DB::table('clients')
            ->where('matrix_client_id', $fromClientId)
            ->where('office_id', $officeId)
            ->update([
                'matrix_client_id' => $toClientId,
                'updated_at' => now(),
            ]);
    }

    /**
     * Mescla tags do cliente-filial no cliente-raiz sem violar o unique composto.
     */
    private function mergeClientCategoryAssignments(int $fromClientId, int $toClientId, int $officeId): void
    {
        if (! Schema::hasTable('client_category_assignments')) {
            return;
        }

        $assignments = DB::table('client_category_assignments')
            ->where('office_id', $officeId)
            ->where('client_id', $fromClientId)
            ->orderBy('id')
            ->get();

        foreach ($assignments as $assignment) {
            DB::table('client_category_assignments')->insertOrIgnore([
                'office_id' => $officeId,
                'client_id' => $toClientId,
                'client_category_id' => $assignment->client_category_id,
                'assigned_by' => $assignment->assigned_by,
                'created_at' => $assignment->created_at,
                'updated_at' => now(),
            ]);
        }

        DB::table('client_category_assignments')
            ->where('office_id', $officeId)
            ->where('client_id', $fromClientId)
            ->delete();
    }

    private function ensureSingleMatrix(int $clientId, int $officeId): void
    {
        $matrices = DB::table('establishments')
            ->where('client_id', $clientId)
            ->where('office_id', $officeId)
            ->where('is_matrix', true)
            ->orderBy('id')
            ->pluck('id');

        if ($matrices->count() <= 1) {
            return;
        }

        $keep = (int) $matrices->first();
        DB::table('establishments')
            ->where('client_id', $clientId)
            ->where('office_id', $officeId)
            ->where('is_matrix', true)
            ->where('id', '<>', $keep)
            ->update([
                'is_matrix' => false,
                'updated_at' => now(),
            ]);
    }

    private function writeMap(
        int $sourceId,
        ?int $targetId,
        int $officeId,
        string $status,
        string $notes,
    ): void {
        DB::table('fiscal_model_migration_maps')->updateOrInsert(
            [
                'aggregate' => FiscalModelAggregates::TENANCY_CADASTRO,
                'source_table' => 'clients',
                'source_id' => (string) $sourceId,
            ],
            [
                'target_table' => $targetId !== null ? 'clients' : null,
                'target_id' => $targetId !== null ? (string) $targetId : null,
                'office_id' => $officeId,
                'correlation_id' => sprintf('collapse:client:%d', $sourceId),
                'status' => $status,
                'notes_sanitized' => $notes,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }
}

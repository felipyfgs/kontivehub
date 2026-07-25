<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A documentação pública do Integra Contador expõe os ambientes Trial e
 * Produção. Remove o legado interno HOMOLOGATION antes de o enum deixar de
 * aceitá-lo, evitando casts inválidos nas bases já instaladas.
 *
 * A operação é deliberadamente irreversível: dados de Homologação não são
 * evidência fiscal e não devem ser promovidos nem mesclados ao Trial.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TABLES = [
        'client_procuracao_snapshots',
        'fiscal_mutation_operations',
        'office_serpro_authorizations',
        'office_serpro_onboarding_states',
        'serpro_api_usage_entries',
        'serpro_api_usage_reservations',
        'serpro_async_job_runs',
        'serpro_contracts',
        'serpro_credential_connection_evidences',
        'serpro_credential_versions',
        'serpro_dte_canary_requests',
        'serpro_eventos_runs',
        'serpro_external_gates',
        'serpro_office_quantity_usage_limits',
        'serpro_operation_attempts',
        'serpro_quantity_usage_limits',
        'serpro_readiness_runs',
        'serpro_rollout_approvals',
        'serpro_service_catalog_entries',
        'serpro_term_versions',
        'serpro_usage_budgets',
        'serpro_usage_incidents',
        'tax_proxy_powers',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'environment')) {
                DB::table($table)->where('environment', 'HOMOLOGATION')->delete();
            }
        }
    }

    public function down(): void
    {
        // Não recria dados de um ambiente SERPRO que não é suportado.
    }
};

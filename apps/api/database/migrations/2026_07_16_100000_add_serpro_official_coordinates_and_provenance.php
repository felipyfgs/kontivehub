<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campos oficiais de catálogo + proveniência fiscal (aditivo — não reescreve migrations aplicadas).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('serpro_service_catalog_entries')) {
            Schema::table('serpro_service_catalog_entries', function (Blueprint $table): void {
                if (! Schema::hasColumn('serpro_service_catalog_entries', 'operation_key')) {
                    $table->string('operation_key', 120)->nullable()->after('id');
                    $table->string('id_sistema', 80)->nullable()->after('operation_code');
                    $table->string('id_servico', 120)->nullable()->after('id_sistema');
                    $table->string('versao_sistema', 20)->nullable()->after('id_servico');
                    $table->string('functional_route', 20)->nullable()->after('versao_sistema');
                    $table->string('official_state', 30)->nullable()->after('functional_route');
                    $table->string('platform_support', 30)->nullable()->after('official_state');
                    $table->string('dados_mode', 20)->nullable()->after('platform_support');
                    $table->index('operation_key', 'serpro_catalog_operation_key_idx');
                }
            });
        }

        if (Schema::hasTable('serpro_operation_catalog')
            && ! Schema::hasColumn('serpro_operation_catalog', 'operation_key')) {
            Schema::table('serpro_operation_catalog', function (Blueprint $table): void {
                $table->string('operation_key', 120)->nullable()->after('id');
                $table->index('operation_key', 'serpro_op_catalog_operation_key_idx');
            });
        }

        if (Schema::hasTable('serpro_api_usage_entries')) {
            Schema::table('serpro_api_usage_entries', function (Blueprint $table): void {
                if (! Schema::hasColumn('serpro_api_usage_entries', 'operation_key')) {
                    $table->string('operation_key', 120)->nullable()->after('operation_code');
                    $table->index('operation_key', 'serpro_usage_operation_key_idx');
                }
                if (! Schema::hasColumn('serpro_api_usage_entries', 'request_tag')) {
                    $table->string('request_tag', 32)->nullable()->after('correlation_id');
                    $table->index('request_tag', 'serpro_usage_request_tag_idx');
                }
                if (! Schema::hasColumn('serpro_api_usage_entries', 'functional_route')) {
                    $table->string('functional_route', 20)->nullable()->after('request_tag');
                }
                if (! Schema::hasColumn('serpro_api_usage_entries', 'is_simulated')) {
                    $table->boolean('is_simulated')->default(false)->after('functional_route');
                }
            });
        }

        if (Schema::hasTable('serpro_api_usage_reservations')) {
            Schema::table('serpro_api_usage_reservations', function (Blueprint $table): void {
                if (! Schema::hasColumn('serpro_api_usage_reservations', 'operation_key')) {
                    $table->string('operation_key', 120)->nullable()->after('operation_code');
                }
                if (! Schema::hasColumn('serpro_api_usage_reservations', 'is_simulated')) {
                    $table->boolean('is_simulated')->default(false)->after('shadow_mode');
                }
                if (! Schema::hasColumn('serpro_api_usage_reservations', 'request_tag')) {
                    $table->string('request_tag', 32)->nullable()->after('correlation_id');
                }
                if (! Schema::hasColumn('serpro_api_usage_reservations', 'functional_route')) {
                    $table->string('functional_route', 20)->nullable()->after('request_tag');
                }
            });
        }

        if (Schema::hasTable('office_serpro_authorizations')
            && ! Schema::hasColumn('office_serpro_authorizations', 'termo_authorization_state')) {
            Schema::table('office_serpro_authorizations', function (Blueprint $table): void {
                $table->string('termo_authorization_state', 30)->nullable()->after('termo_uploaded_at');
                $table->string('procurador_etag', 255)->nullable()->after('procurador_token_expires_at');
            });
        }

        $this->addProvenanceColumns('fiscal_monitoring_runs');
        $this->addProvenanceColumns('fiscal_evidence_artifacts');
        $this->addProvenanceColumns('fiscal_snapshots');

        // Legado sem prova → UNVERIFIED (não promove a SERPRO_REAL)
        foreach (['fiscal_monitoring_runs', 'fiscal_evidence_artifacts', 'fiscal_snapshots'] as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'source_provenance')) {
                continue;
            }
            DB::table($table)
                ->whereNull('source_provenance')
                ->update([
                    'source_provenance' => 'UNVERIFIED',
                    'verification_state' => 'UNVERIFIED',
                ]);
        }

        // Corrigir poder SITFIS legado → 00002 e mapear chaves internas conhecidas
        if (Schema::hasTable('serpro_service_catalog_entries')) {
            DB::table('serpro_service_catalog_entries')
                ->where('solution_code', 'INTEGRA_SITFIS')
                ->orWhere('service_code', 'SITFIS')
                ->update(['required_proxy_power' => '00002']);

            // Pré-mapear operações SITFIS legadas se operation_key ainda nulo
            if (Schema::hasColumn('serpro_service_catalog_entries', 'operation_key')) {
                DB::table('serpro_service_catalog_entries')
                    ->where('operation_code', 'SOLICITAR_RELATORIO')
                    ->whereNull('operation_key')
                    ->update([
                        'operation_key' => 'sitfis.solicitar_protocolo',
                        'id_sistema' => 'SITFIS',
                        'id_servico' => 'SOLICITARPROTOCOLO91',
                        'versao_sistema' => '2.0',
                        'functional_route' => 'Apoiar',
                        'official_state' => 'PRODUCTION',
                        'platform_support' => 'IMPLEMENTED',
                        'dados_mode' => 'EMPTY',
                        'required_proxy_power' => '00002',
                    ]);

                DB::table('serpro_service_catalog_entries')
                    ->where('operation_code', 'EMITIR_RELATORIO')
                    ->whereNull('operation_key')
                    ->update([
                        'operation_key' => 'sitfis.emitir_relatorio',
                        'id_sistema' => 'SITFIS',
                        'id_servico' => 'RELATORIOSITFIS92',
                        'versao_sistema' => '2.0',
                        'functional_route' => 'Emitir',
                        'official_state' => 'PRODUCTION',
                        'platform_support' => 'IMPLEMENTED',
                        'dados_mode' => 'JSON_STRING',
                        'required_proxy_power' => '00002',
                    ]);
            }
        }

        if (Schema::hasTable('serpro_operation_catalog')
            && Schema::hasColumn('serpro_operation_catalog', 'operation_key')) {
            DB::table('serpro_operation_catalog')
                ->where('system_code', 'like', '%SITFIS%')
                ->where('operation_code', 'like', '%SOLICIT%')
                ->whereNull('operation_key')
                ->update(['operation_key' => 'sitfis.solicitar_protocolo']);

            DB::table('serpro_operation_catalog')
                ->where('system_code', 'like', '%SITFIS%')
                ->where('operation_code', 'like', '%EMIT%')
                ->whereNull('operation_key')
                ->update(['operation_key' => 'sitfis.emitir_relatorio']);
        }
    }

    public function down(): void
    {
        // Aditivo: down remove apenas colunas novas se existirem (sem apagar dados de evidência).
        $this->dropIfExistsColumns('serpro_service_catalog_entries', [
            'operation_key', 'id_sistema', 'id_servico', 'versao_sistema',
            'functional_route', 'official_state', 'platform_support', 'dados_mode',
        ]);
        $this->dropIfExistsColumns('serpro_operation_catalog', ['operation_key']);
        $this->dropIfExistsColumns('serpro_api_usage_entries', [
            'operation_key', 'request_tag', 'functional_route', 'is_simulated',
        ]);
        $this->dropIfExistsColumns('serpro_api_usage_reservations', [
            'operation_key', 'is_simulated', 'request_tag', 'functional_route',
        ]);
        $this->dropIfExistsColumns('office_serpro_authorizations', [
            'termo_authorization_state', 'procurador_etag',
        ]);
        foreach (['fiscal_monitoring_runs', 'fiscal_evidence_artifacts', 'fiscal_snapshots'] as $table) {
            $this->dropIfExistsColumns($table, [
                'source_provenance', 'verification_state', 'operation_key',
            ]);
        }
    }

    private function addProvenanceColumns(string $table): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        if (Schema::hasColumn($table, 'source_provenance')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($table): void {
            $blueprint->string('source_provenance', 20)->nullable()->after('id');
            $blueprint->string('verification_state', 20)->nullable()->after('source_provenance');
            if (! Schema::hasColumn($table, 'operation_key')) {
                $blueprint->string('operation_key', 120)->nullable()->after('verification_state');
            }
            $blueprint->index('source_provenance', $table.'_provenance_idx');
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropIfExistsColumns(string $table, array $columns): void
    {
        if (! Schema::hasTable($table)) {
            return;
        }
        Schema::table($table, function (Blueprint $blueprint) use ($table, $columns): void {
            foreach ($columns as $col) {
                if (Schema::hasColumn($table, $col)) {
                    $blueprint->dropColumn($col);
                }
            }
        });
    }
};

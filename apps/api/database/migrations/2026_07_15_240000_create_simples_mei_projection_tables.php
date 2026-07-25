<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projeções Simples/MEI (tasks 8.3–8.5):
 * - client_tax_regime_periods: vigências de regime (sem misturar SN/MEI)
 * - fiscal_guide_stubs: DAS assistido (emissão ≠ pagamento)
 * Amplia catálogo SERPRO e ledger de operações SN/MEI.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_tax_regime_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('regime_code', 40);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('source_system', 40)->nullable();
            $table->string('source_service', 80)->nullable();
            $table->unsignedBigInteger('source_run_id')->nullable();
            $table->unsignedBigInteger('evidence_artifact_id')->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'regime_code', 'effective_from'],
                'ctrp_office_client_regime_from_uq'
            );
            $table->index(['office_id', 'client_id', 'effective_from'], 'ctrp_office_client_from_idx');
        });

        Schema::create('fiscal_guide_stubs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80);
            $table->string('regime_family', 40);
            $table->string('period_key', 20);
            $table->string('document_number', 80)->nullable();
            $table->date('due_date')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('emission_status', 30)->default('STUB');
            $table->string('payment_status', 30)->default('UNKNOWN');
            $table->boolean('is_external_call')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'client_id', 'period_key'], 'fgs_office_client_period_idx');
            $table->index(['office_id', 'payment_status'], 'fgs_office_payment_idx');
        });

        $this->seedExpandedCatalog();
        $this->seedUsageOperations();
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_guide_stubs');
        Schema::dropIfExists('client_tax_regime_periods');
    }

    private function seedExpandedCatalog(): void
    {
        $now = now();
        $ops = [
            // solution, service, operation, label, mutating, power, billable, cache
            ['INTEGRA_SN', 'PGDASD', 'CONSULTAR_RECIBO', 'Consultar recibo PGDAS-D', false, 'PGDASD', 'CONSULTA', 3600],
            ['INTEGRA_SN', 'PGDASD', 'CONSULTAR_EXTRATO', 'Consultar extrato PGDAS-D', false, 'PGDASD', 'CONSULTA', 3600],
            ['INTEGRA_SN', 'PGDASD', 'GERAR_DAS', 'Gerar DAS PGDAS-D', true, 'PGDASD', 'EMISSAO', 0],
            ['INTEGRA_SN', 'DEFIS', 'TRANSMITIR', 'Transmitir DEFIS', true, 'DEFIS', 'DECLARACAO', 0],
            ['INTEGRA_SN', 'REGIME_APURACAO', 'CONSULTAR', 'Consultar Regime de Apuração', false, 'REGIME_APURACAO', 'CONSULTA', 86400],
            ['INTEGRA_MEI', 'PGMEI', 'CONSULTAR_DAS', 'Consultar DAS MEI', false, 'PGMEI', 'CONSULTA', 3600],
            ['INTEGRA_MEI', 'PGMEI', 'GERAR_DAS', 'Gerar DAS MEI', true, 'PGMEI', 'EMISSAO', 0],
            ['INTEGRA_MEI', 'DASN_SIMEI', 'CONSULTAR', 'Consultar DASN-SIMEI', false, 'DASN_SIMEI', 'CONSULTA', 3600],
            ['INTEGRA_MEI', 'DASN_SIMEI', 'TRANSMITIR', 'Transmitir DASN-SIMEI', true, 'DASN_SIMEI', 'DECLARACAO', 0],
        ];

        foreach (['TRIAL', 'HOMOLOGATION', 'PRODUCTION'] as $env) {
            foreach ($ops as [$solution, $service, $operation, $label, $mutating, $power, $billable, $cache]) {
                $exists = DB::table('serpro_service_catalog_entries')
                    ->where('environment', $env)
                    ->where('solution_code', $solution)
                    ->where('service_code', $service)
                    ->where('operation_code', $operation)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('serpro_service_catalog_entries')->insert([
                    'catalog_version' => 1,
                    'environment' => $env,
                    'solution_code' => $solution,
                    'service_code' => $service,
                    'operation_code' => $operation,
                    'label' => $label,
                    'is_mutating' => $mutating,
                    'is_enabled' => ! $mutating,
                    'required_proxy_power' => $power,
                    'billable_class' => $billable,
                    'cache_ttl_seconds' => $cache,
                    'rate_limit_per_minute' => $mutating ? 10 : 30,
                    'coverage' => 'KNOWN',
                    'metadata' => null,
                    'effective_from' => $now,
                    'effective_to' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function seedUsageOperations(): void
    {
        $now = now();
        $from = $now->copy()->subYear();
        $ops = [
            ['INTEGRA_SN', 'PGDASD', 'CONSULTAR_DECLARACAO', 'CONSULTA', true, 'Consulta PGDAS-D'],
            ['INTEGRA_SN', 'PGDASD', 'CONSULTAR_RECIBO', 'CONSULTA', true, 'Recibo PGDAS-D'],
            ['INTEGRA_SN', 'PGDASD', 'CONSULTAR_EXTRATO', 'CONSULTA', true, 'Extrato PGDAS-D'],
            ['INTEGRA_SN', 'PGDASD', 'MONITOR', 'CONSULTA', true, 'Monitor PGDAS-D'],
            ['INTEGRA_SN', 'PGDASD', 'GERAR_DAS', 'EMISSAO', false, 'Gerar DAS SN'],
            ['INTEGRA_SN', 'PGDASD', 'TRANSMITIR', 'DECLARACAO', false, 'Transmitir PGDAS-D'],
            ['INTEGRA_SN', 'DEFIS', 'CONSULTAR', 'CONSULTA', true, 'Consulta DEFIS'],
            ['INTEGRA_SN', 'DEFIS', 'MONITOR', 'CONSULTA', true, 'Monitor DEFIS'],
            ['INTEGRA_SN', 'DEFIS', 'TRANSMITIR', 'DECLARACAO', false, 'Transmitir DEFIS'],
            ['INTEGRA_SN', 'REGIME_APURACAO', 'CONSULTAR', 'CONSULTA', true, 'Regime Apuração'],
            ['INTEGRA_SN', 'REGIME_APURACAO', 'MONITOR', 'CONSULTA', true, 'Monitor Regime'],
            ['INTEGRA_MEI', 'PGMEI', 'CONSULTAR', 'CONSULTA', true, 'Consulta PGMEI'],
            ['INTEGRA_MEI', 'PGMEI', 'CONSULTAR_DAS', 'CONSULTA', true, 'Consulta DAS MEI'],
            ['INTEGRA_MEI', 'PGMEI', 'MONITOR', 'CONSULTA', true, 'Monitor PGMEI'],
            ['INTEGRA_MEI', 'PGMEI', 'GERAR_DAS', 'EMISSAO', false, 'Gerar DAS MEI'],
            ['INTEGRA_MEI', 'CCMEI', 'CONSULTAR', 'CONSULTA', true, 'Consulta CCMEI'],
            ['INTEGRA_MEI', 'CCMEI', 'MONITOR', 'CONSULTA', true, 'Monitor CCMEI'],
            ['INTEGRA_MEI', 'DASN_SIMEI', 'CONSULTAR', 'CONSULTA', true, 'Consulta DASN-SIMEI'],
            ['INTEGRA_MEI', 'DASN_SIMEI', 'MONITOR', 'CONSULTA', true, 'Monitor DASN-SIMEI'],
            ['INTEGRA_MEI', 'DASN_SIMEI', 'TRANSMITIR', 'DECLARACAO', false, 'Transmitir DASN-SIMEI'],
        ];

        foreach ($ops as [$system, $service, $operation, $class, $essential, $label]) {
            $exists = DB::table('serpro_operation_catalog')
                ->where('system_code', $system)
                ->where('service_code', $service)
                ->where('operation_code', $operation)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('serpro_operation_catalog')->insert([
                'system_code' => $system,
                'service_code' => $service,
                'operation_code' => $operation,
                'consumption_class' => $class,
                'is_essential' => $essential,
                'effective_from' => $from,
                'effective_to' => null,
                'label' => $label,
                'notes' => 'Seed migration 2026_07_15_240000',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};

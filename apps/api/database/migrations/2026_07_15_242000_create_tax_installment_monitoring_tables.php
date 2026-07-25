<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Parcelamentos SN/MEI (tasks 9.5–9.7).
 *
 * - Pedidos/modalidades/parcelas/pagamentos tenant-scoped
 * - Unique por office+client+modalidade+id externo (não funde modalidades)
 * - tax_guide_id é referência fraca (unsigned) — FK formal na central de guias (246000+)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_installment_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->foreignId('snapshot_id')->nullable()->constrained('fiscal_snapshots')->nullOnDelete();
            $table->string('modality', 20); // PARCSN, PARCMEI, …
            $table->string('regime', 10); // SN | MEI
            $table->string('external_order_id', 80);
            $table->string('situation', 40)->default('UNKNOWN');
            $table->string('source_status', 80)->nullable();
            $table->timestampTz('requested_at')->nullable();
            $table->timestampTz('consolidated_at')->nullable();
            $table->unsignedInteger('parcel_count')->nullable();
            $table->unsignedBigInteger('total_amount_cents')->nullable();
            $table->string('source_system', 40)->default('INTEGRA_PARCELAMENTO');
            $table->string('source_service', 80);
            $table->string('source_operation', 80)->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->timestampTz('observed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'modality', 'external_order_id'],
                'tio_office_client_mod_ext_uq'
            );
            $table->index(['office_id', 'client_id', 'modality'], 'tio_office_client_mod_idx');
            $table->index(['office_id', 'situation'], 'tio_office_situation_idx');
        });

        Schema::create('tax_installment_parcels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('tax_installment_orders')->cascadeOnDelete();
            $table->string('modality', 20);
            $table->string('parcel_key', 40);
            $table->unsignedSmallInteger('parcel_number')->nullable();
            $table->string('status', 32)->default('UNKNOWN');
            $table->string('source_status', 80)->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->unsignedBigInteger('amount_cents')->nullable();
            $table->boolean('document_available')->default(false);
            $table->string('payment_status', 30)->default('NONE');
            $table->timestampTz('paid_at')->nullable();
            $table->unsignedBigInteger('payment_id')->nullable();
            /** Referência à central de guias (sem FK rígida — tabela criada em 246000). */
            $table->unsignedBigInteger('tax_guide_id')->nullable()->index();
            $table->string('logical_key', 160);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'order_id', 'parcel_key'],
                'tip_office_order_parcel_uq'
            );
            $table->unique(
                ['office_id', 'client_id', 'logical_key'],
                'tip_office_client_logical_uq'
            );
            $table->index(['office_id', 'client_id', 'status', 'due_at'], 'tip_office_client_status_due_idx');
            $table->index(['office_id', 'modality'], 'tip_office_modality_idx');
        });

        Schema::create('tax_installment_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('tax_installment_orders')->cascadeOnDelete();
            $table->foreignId('parcel_id')->constrained('tax_installment_parcels')->cascadeOnDelete();
            $table->string('modality', 20);
            $table->string('status', 32)->default('UNKNOWN');
            $table->unsignedBigInteger('amount_cents')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->string('payment_ref', 120)->nullable();
            $table->string('evidence_sha256', 64)->nullable();
            $table->foreignId('run_id')->nullable()->constrained('fiscal_monitoring_runs')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'parcel_id', 'payment_ref'],
                'tipay_office_parcel_ref_uq'
            );
            $table->index(['office_id', 'client_id', 'status'], 'tipay_office_client_status_idx');
        });

        Schema::table('tax_installment_parcels', function (Blueprint $table) {
            $table->foreign('payment_id')
                ->references('id')
                ->on('tax_installment_payments')
                ->nullOnDelete();
        });

        $this->seedParcelamentoCatalog();
    }

    public function down(): void
    {
        Schema::table('tax_installment_parcels', function (Blueprint $table) {
            $table->dropForeign(['payment_id']);
        });
        Schema::dropIfExists('tax_installment_payments');
        Schema::dropIfExists('tax_installment_parcels');
        Schema::dropIfExists('tax_installment_orders');
    }

    private function seedParcelamentoCatalog(): void
    {
        if (! Schema::hasTable('serpro_service_catalog_entries')) {
            return;
        }

        $now = now();
        $modalities = [
            'PARCSN', 'PARCSN-ESP', 'PERTSN', 'RELPSN',
            'PARCMEI', 'PARCMEI-ESP', 'PERTMEI', 'RELPMEI',
        ];

        $ops = [
            ['CONSULTAR_PEDIDOS', 'Consultar pedidos de parcelamento', false, true, 'CONSULTA', 1800],
            ['CONSULTAR_PARCELAMENTO', 'Consultar detalhe do parcelamento', false, true, 'CONSULTA', 1800],
            ['CONSULTAR_PARCELAS', 'Consultar parcelas para impressão', false, true, 'CONSULTA', 1800],
            ['CONSULTAR_PAGAMENTO', 'Consultar detalhes de pagamento', false, true, 'CONSULTA', 1800],
            ['MONITOR', 'Monitorar parcelamento (orquestração)', false, true, 'CONSULTA', 1800],
            // Emissão assistida de documento — habilitada no catálogo (não é adesão)
            ['EMITIR_DOCUMENTO', 'Emitir documento de arrecadação da parcela', false, true, 'EMISSAO', 0],
            // Mutantes do piloto — desabilitados
            ['ADERIR', 'Aderir a parcelamento (mutante)', true, false, 'DECLARACAO', 0],
            ['REPARCELAR', 'Reparcelar (mutante)', true, false, 'DECLARACAO', 0],
            ['DESISTIR', 'Desistir de parcelamento (mutante)', true, false, 'DECLARACAO', 0],
        ];

        $rows = [];
        foreach (['TRIAL', 'HOMOLOGATION', 'PRODUCTION'] as $env) {
            foreach ($modalities as $modality) {
                foreach ($ops as [$op, $label, $mutating, $enabled, $billable, $cache]) {
                    $exists = DB::table('serpro_service_catalog_entries')
                        ->where('environment', $env)
                        ->where('solution_code', 'INTEGRA_PARCELAMENTO')
                        ->where('service_code', $modality)
                        ->where('operation_code', $op)
                        ->exists();
                    if ($exists) {
                        continue;
                    }

                    $rows[] = [
                        'catalog_version' => 1,
                        'environment' => $env,
                        'solution_code' => 'INTEGRA_PARCELAMENTO',
                        'service_code' => $modality,
                        'operation_code' => $op,
                        'label' => "{$label} ({$modality})",
                        'is_mutating' => $mutating,
                        'is_enabled' => $enabled,
                        'required_proxy_power' => $modality,
                        'billable_class' => $billable,
                        'cache_ttl_seconds' => $cache,
                        'rate_limit_per_minute' => $mutating ? 10 : 30,
                        'coverage' => 'FULL',
                        'metadata' => null,
                        'effective_from' => $now,
                        'effective_to' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('serpro_service_catalog_entries')->insert($chunk);
        }
    }
};

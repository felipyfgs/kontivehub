<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger imutável de consumo SERPRO (6.1–6.2).
 *
 * Plano de dados (COM office_id): reservas, entradas, agregados por tenant.
 * Plano de controle (SEM office_id): versões de preço, catálogo de operação,
 * conciliações oficiais e agregados globais (office_id null + scope GLOBAL).
 *
 * Seed determinístico: versão de preço v1 + catálogo mínimo de classes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serpro_price_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version_code', 40)->unique();
            $table->string('name', 120);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('currency', 3)->default('BRL');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'effective_from']);
        });

        Schema::create('serpro_price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_version_id')->constrained('serpro_price_versions')->cascadeOnDelete();
            $table->string('consumption_class', 30);
            // null = default da classe na versão
            $table->string('system_code', 40)->nullable();
            $table->string('service_code', 80)->nullable();
            $table->string('operation_code', 80)->nullable();
            $table->unsignedInteger('min_quantity')->default(1);
            $table->unsignedInteger('max_quantity')->nullable(); // null = infinito
            // micros da moeda (1 BRL = 1_000_000 micros)
            $table->unsignedBigInteger('unit_cost_micros');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(
                ['price_version_id', 'consumption_class', 'system_code', 'service_code', 'operation_code'],
                'serpro_price_tiers_lookup_idx'
            );
        });

        Schema::create('serpro_operation_catalog', function (Blueprint $table) {
            $table->id();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80);
            $table->string('consumption_class', 30);
            $table->boolean('is_essential')->default(false);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->string('label', 160)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(
                ['system_code', 'service_code', 'operation_code', 'effective_from'],
                'serpro_op_catalog_unique'
            );
            $table->index(
                ['system_code', 'service_code', 'operation_code', 'effective_from', 'effective_to'],
                'serpro_op_catalog_lookup_idx'
            );
        });

        Schema::create('serpro_api_usage_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->string('idempotency_key', 120)->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            // Contribuinte: referência interna; CNPJ completo NÃO vai para labels de log.
            $table->string('contributor_ref', 40)->nullable();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80);
            $table->string('consumption_class', 30);
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('is_essential')->default(false);
            $table->string('status', 32);
            $table->string('correlation_id', 64)->nullable();
            $table->foreignId('price_version_id')->nullable()->constrained('serpro_price_versions')->nullOnDelete();
            // null quando DESCONHECIDA (não inventar zero)
            $table->unsignedBigInteger('estimated_cost_micros')->nullable();
            $table->boolean('shadow_mode')->default(true);
            $table->boolean('would_block')->default(false);
            $table->string('block_reason', 80)->nullable();
            $table->string('result', 30)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('possibly_billable')->nullable();
            $table->timestampTz('reserved_at');
            $table->timestampTz('finalized_at')->nullable();
            $table->timestamps();

            $table->index(['office_id', 'reserved_at']);
            $table->index(['office_id', 'status', 'reserved_at']);
            $table->index(['correlation_id']);
        });

        Schema::create('serpro_api_usage_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reservation_id')->nullable()->constrained('serpro_api_usage_reservations')->nullOnDelete();
            $table->string('idempotency_key', 120)->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contributor_ref', 40)->nullable();
            $table->string('system_code', 40);
            $table->string('service_code', 80);
            $table->string('operation_code', 80);
            $table->string('consumption_class', 30);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('result', 30);
            $table->string('correlation_id', 64)->nullable();
            $table->foreignId('price_version_id')->nullable()->constrained('serpro_price_versions')->nullOnDelete();
            // Preserva estimativa histórica; null se DESCONHECIDA
            $table->unsignedBigInteger('estimated_cost_micros')->nullable();
            $table->boolean('is_billable_attempt')->default(true);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->boolean('shadow_mode')->default(true);
            $table->timestampTz('occurred_at');
            // Imutável: só created_at (sem updated_at)
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['office_id', 'occurred_at']);
            $table->index(['office_id', 'service_code', 'occurred_at']);
            $table->index(['office_id', 'consumption_class', 'occurred_at']);
            $table->index(['correlation_id']);
            $table->index(['price_version_id']);
        });

        Schema::create('serpro_usage_monthly_aggregates', function (Blueprint $table) {
            $table->id();
            // TENANT | GLOBAL
            $table->string('scope', 20);
            $table->foreignId('office_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            // null = todas as dimensões
            $table->string('system_code', 40)->nullable();
            $table->string('service_code', 80)->nullable();
            $table->string('consumption_class', 30)->nullable();
            // chave estável para unique (SQLite-friendly com nulls)
            $table->string('aggregate_key', 191)->unique();
            $table->unsignedBigInteger('entry_count')->default(0);
            $table->unsignedBigInteger('total_quantity')->default(0);
            $table->unsignedBigInteger('total_estimated_cost_micros')->default(0);
            $table->unsignedBigInteger('unknown_class_count')->default(0);
            $table->unsignedBigInteger('billable_attempt_count')->default(0);
            $table->timestampTz('recomputed_at');
            $table->timestamps();

            $table->index(['scope', 'period_year', 'period_month']);
            $table->index(['office_id', 'period_year', 'period_month']);
        });

        Schema::create('serpro_usage_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('official_reference', 120)->nullable();
            $table->string('official_source', 80)->nullable();
            $table->unsignedBigInteger('official_total_cost_micros');
            $table->unsignedBigInteger('internal_total_estimated_cost_micros')->default(0);
            $table->bigInteger('difference_micros')->default(0);
            $table->string('status', 32);
            $table->string('difference_cause', 120)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['period_year', 'period_month', 'official_reference'], 'serpro_recon_period_ref_unique');
            $table->index(['period_year', 'period_month', 'status']);
        });

        // Ajustes/diferenças SEPARADOS do ledger original (não reescrevem entradas).
        Schema::create('serpro_usage_reconciliation_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('serpro_usage_reconciliations')->cascadeOnDelete();
            $table->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $table->string('service_code', 80)->nullable();
            $table->string('consumption_class', 30)->nullable();
            $table->bigInteger('amount_micros');
            $table->string('reason', 120);
            $table->text('notes')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['reconciliation_id', 'office_id']);
        });

        $this->seedDefaults();
    }

    public function down(): void
    {
        Schema::dropIfExists('serpro_usage_reconciliation_adjustments');
        Schema::dropIfExists('serpro_usage_reconciliations');
        Schema::dropIfExists('serpro_usage_monthly_aggregates');
        Schema::dropIfExists('serpro_api_usage_entries');
        Schema::dropIfExists('serpro_api_usage_reservations');
        Schema::dropIfExists('serpro_operation_catalog');
        Schema::dropIfExists('serpro_price_tiers');
        Schema::dropIfExists('serpro_price_versions');
    }

    private function seedDefaults(): void
    {
        $now = now();
        $from = $now->copy()->startOfYear();

        $versionId = DB::table('serpro_price_versions')->insertGetId([
            'version_code' => 'v1-shadow-2026',
            'name' => 'Tabela shadow trial 2026',
            'effective_from' => $from,
            'effective_to' => null,
            'is_active' => true,
            'currency' => 'BRL',
            'notes' => 'Preços estimados para shadow mode; substituir após conciliação SERPRO.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Faixas por classe (unit cost em micros: R$ 0,10 = 100_000)
        $tiers = [
            ['CONSULTA', 100_000, 1, null],
            ['EMISSAO', 500_000, 1, null],
            ['DECLARACAO', 300_000, 1, null],
            ['NAO_FATURAVEL', 0, 1, null],
            // DESCONHECIDA deliberadamente sem faixa → custo null
        ];

        foreach ($tiers as [$class, $cost, $min, $max]) {
            DB::table('serpro_price_tiers')->insert([
                'price_version_id' => $versionId,
                'consumption_class' => $class,
                'system_code' => null,
                'service_code' => null,
                'operation_code' => null,
                'min_quantity' => $min,
                'max_quantity' => $max,
                'unit_cost_micros' => $cost,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Catálogo mínimo de operações (vigência aberta)
        $ops = [
            // system, service, operation, class, essential, label
            ['INTEGRA_CONTADOR', 'SITFIS', 'CONSULTAR_SITUACAO', 'CONSULTA', true, 'Situação fiscal'],
            ['INTEGRA_CONTADOR', 'CAIXA_POSTAL', 'LISTAR_MENSAGENS', 'CONSULTA', true, 'Caixa postal'],
            ['INTEGRA_CONTADOR', 'DCTFWEB', 'CONSULTAR_DECLARACAO', 'CONSULTA', true, 'Consulta DCTFWeb'],
            ['INTEGRA_CONTADOR', 'DCTFWEB', 'TRANSMITIR_DECLARACAO', 'DECLARACAO', false, 'Transmitir DCTFWeb'],
            ['INTEGRA_CONTADOR', 'MIT', 'CONSULTAR_MIT', 'CONSULTA', true, 'Consulta MIT'],
            ['INTEGRA_CONTADOR', 'PARCELAMENTO', 'CONSULTAR_PARCELA', 'CONSULTA', true, 'Consulta parcelamento'],
            ['INTEGRA_CONTADOR', 'PGDAS', 'CONSULTAR_DECLARACAO', 'CONSULTA', true, 'Consulta PGDAS'],
            ['INTEGRA_CONTADOR', 'PGMEI', 'CONSULTAR_DAS', 'CONSULTA', true, 'Consulta DAS MEI'],
            ['INTEGRA_CONTADOR', 'GUIAS', 'EMITIR_GUIA', 'EMISSAO', false, 'Emissão de guia'],
            ['INTEGRA_CONTADOR', 'PROCURACOES', 'CONSULTAR_PROCURACAO', 'CONSULTA', true, 'Consulta procuração'],
            ['INTEGRA_CONTADOR', 'AUTH', 'OBTER_TOKEN', 'NAO_FATURAVEL', true, 'OAuth token'],
            ['INTEGRA_CONTADOR', 'AUTH', 'AUTENTICAR_PROCURADOR', 'NAO_FATURAVEL', true, 'Autenticar procurador'],
        ];

        foreach ($ops as [$system, $service, $operation, $class, $essential, $label]) {
            DB::table('serpro_operation_catalog')->insert([
                'system_code' => $system,
                'service_code' => $service,
                'operation_code' => $operation,
                'consumption_class' => $class,
                'is_essential' => $essential,
                'effective_from' => $from,
                'effective_to' => null,
                'label' => $label,
                'notes' => 'Seed migration 2026_07_15_220000',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};

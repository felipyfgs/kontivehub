<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo versionado de obrigações declaratórias + projeções tenant-scoped (tasks 11.1–11.5).
 *
 * Plano de controle (global, sem office_id):
 * - tax_obligation_definitions / versions / regime_rules
 * - tax_deadline_calendar_versions / tax_deadline_rules
 *
 * Plano de dados (office_id obrigatório):
 * - tax_obligation_projections
 * - tax_delivery_evidences
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_obligation_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->string('fiscal_category_code', 60)->nullable(); // ref lógica a fiscal_categories.code
            $table->string('module_key', 40)->nullable();
            $table->string('system_code', 40)->nullable();
            $table->string('service_code', 80)->nullable();
            $table->string('period_granularity', 20)->default('MONTHLY');
            $table->string('default_timezone', 64)->default('America/Sao_Paulo');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->json('supported_operations')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['module_key', 'is_active'], 'tod_module_active_idx');
        });

        Schema::create('tax_obligation_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obligation_definition_id')
                ->constrained('tax_obligation_definitions')
                ->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('rule_key', 80); // identificador estável da regra
            $table->string('default_applicability', 30)->default('UNKNOWN');
            $table->text('rule_basis')->nullable();
            $table->string('source_ref', 255)->nullable();
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->boolean('is_current')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['obligation_definition_id', 'version'],
                'tov_obligation_version_uq'
            );
            $table->unique(
                ['obligation_definition_id', 'rule_key'],
                'tov_obligation_rule_key_uq'
            );
            $table->index(
                ['obligation_definition_id', 'is_current'],
                'tov_obligation_current_idx'
            );
        });

        Schema::create('tax_obligation_regime_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('obligation_version_id')
                ->constrained('tax_obligation_versions')
                ->cascadeOnDelete();
            $table->string('tax_regime', 40); // TaxRegimeCode
            $table->string('applicability', 30);
            $table->text('rule_basis')->nullable();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['obligation_version_id', 'tax_regime'],
                'torr_version_regime_uq'
            );
            $table->index(
                ['obligation_version_id', 'applicability'],
                'torr_version_appl_idx'
            );
        });

        Schema::create('tax_deadline_calendar_versions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60); // RFB_NATIONAL
            $table->unsignedInteger('version');
            $table->string('label', 160);
            $table->string('timezone', 64)->default('America/Sao_Paulo');
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->boolean('is_current')->default(false);
            $table->string('source_ref', 255)->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['code', 'version'], 'tdcv_code_version_uq');
            $table->index(['code', 'is_current'], 'tdcv_code_current_idx');
        });

        Schema::create('tax_deadline_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('calendar_version_id')
                ->constrained('tax_deadline_calendar_versions')
                ->cascadeOnDelete();
            $table->foreignId('obligation_definition_id')
                ->nullable()
                ->constrained('tax_obligation_definitions')
                ->nullOnDelete();
            $table->string('period_granularity', 20)->default('MONTHLY');
            /** Dia do mês do vencimento (1–31); null se anual fixo por mês/dia. */
            $table->unsignedTinyInteger('due_day')->nullable();
            /** Offset de mês relativo à competência (1 = mês seguinte). */
            $table->smallInteger('due_month_offset')->default(1);
            /** Para anuais: mês fixo do vencimento (1–12). */
            $table->unsignedTinyInteger('fixed_due_month')->nullable();
            $table->unsignedTinyInteger('fixed_due_day')->nullable();
            $table->string('business_day_adjustment', 20)->default('NONE');
            $table->string('timezone', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['calendar_version_id', 'obligation_definition_id'],
                'tdr_cal_obligation_idx'
            );
        });

        Schema::create('tax_obligation_projections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('obligation_definition_id')
                ->constrained('tax_obligation_definitions')
                ->cascadeOnDelete();
            $table->foreignId('obligation_version_id')
                ->nullable()
                ->constrained('tax_obligation_versions')
                ->nullOnDelete();
            $table->foreignId('calendar_version_id')
                ->nullable()
                ->constrained('tax_deadline_calendar_versions')
                ->nullOnDelete();
            $table->foreignId('competence_id')
                ->nullable()
                ->constrained('fiscal_competences')
                ->nullOnDelete();
            $table->string('period_key', 20); // YYYY | YYYY-MM | YYYY-QN
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month')->nullable();
            $table->string('applicability', 30)->default('UNKNOWN');
            $table->string('situation', 30)->default('UNKNOWN');
            $table->string('delivery_status', 30)->default('UNKNOWN');
            $table->timestampTz('due_at')->nullable();
            $table->json('due_rule_snapshot')->nullable();
            $table->json('due_history')->nullable(); // cálculos anteriores auditáveis
            $table->text('applicability_basis')->nullable();
            $table->boolean('is_open')->default(true);
            $table->timestampTz('closed_at')->nullable();
            $table->foreignId('conclusive_evidence_id')->nullable(); // FK adiada
            $table->foreignId('evidence_artifact_id')
                ->nullable()
                ->constrained('fiscal_evidence_artifacts')
                ->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['office_id', 'client_id', 'obligation_definition_id', 'period_key'],
                'top_office_client_obl_period_uq'
            );
            $table->index(
                ['office_id', 'client_id', 'situation'],
                'top_office_client_sit_idx'
            );
            $table->index(
                ['office_id', 'is_open', 'due_at'],
                'top_office_open_due_idx'
            );
            $table->index(
                ['office_id', 'applicability', 'delivery_status'],
                'top_office_appl_delivery_idx'
            );
            $table->index(
                ['office_id', 'period_year', 'period_month'],
                'top_office_period_idx'
            );
        });

        Schema::create('tax_delivery_evidences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('office_id')->constrained()->cascadeOnDelete();
            $table->foreignId('projection_id')
                ->constrained('tax_obligation_projections')
                ->cascadeOnDelete();
            $table->string('kind', 40);
            $table->string('protocol_number', 80)->nullable();
            $table->string('receipt_number', 80)->nullable();
            $table->boolean('is_conclusive')->default(false);
            $table->string('source', 80);
            $table->string('source_version', 40)->nullable();
            $table->timestampTz('observed_at');
            $table->foreignId('evidence_artifact_id')
                ->nullable()
                ->constrained('fiscal_evidence_artifacts')
                ->nullOnDelete();
            $table->foreignId('run_id')
                ->nullable()
                ->constrained('fiscal_monitoring_runs')
                ->nullOnDelete();
            $table->string('payload_digest', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['office_id', 'projection_id', 'is_conclusive'],
                'tde_office_proj_concl_idx'
            );
            $table->index(
                ['office_id', 'kind'],
                'tde_office_kind_idx'
            );
        });

        Schema::table('tax_obligation_projections', function (Blueprint $table) {
            $table->foreign('conclusive_evidence_id')
                ->references('id')
                ->on('tax_delivery_evidences')
                ->nullOnDelete();
        });

        $this->seedCatalog();
    }

    public function down(): void
    {
        Schema::table('tax_obligation_projections', function (Blueprint $table) {
            $table->dropForeign(['conclusive_evidence_id']);
        });
        Schema::dropIfExists('tax_delivery_evidences');
        Schema::dropIfExists('tax_obligation_projections');
        Schema::dropIfExists('tax_deadline_rules');
        Schema::dropIfExists('tax_deadline_calendar_versions');
        Schema::dropIfExists('tax_obligation_regime_rules');
        Schema::dropIfExists('tax_obligation_versions');
        Schema::dropIfExists('tax_obligation_definitions');
    }

    private function seedCatalog(): void
    {
        $now = now();
        $tz = 'America/Sao_Paulo';

        $definitions = [
            // code, name, cat, module, system, service, granularity, sort, ops, description
            [
                'PGDAS_D',
                'PGDAS-D',
                'SIMPLES_NACIONAL',
                'simples_mei',
                'INTEGRA_SN',
                'PGDASD',
                'MONTHLY',
                10,
                ['CONSULTAR_DECLARACAO', 'CONSULTAR_RECIBO'],
                'Declaração mensal do Simples Nacional (PGDAS-D).',
            ],
            [
                'DEFIS',
                'DEFIS',
                'SIMPLES_NACIONAL',
                'simples_mei',
                'INTEGRA_SN',
                'DEFIS',
                'ANNUAL',
                20,
                ['CONSULTAR'],
                'Declaração de Informações Socioeconômicas e Fiscais (anual).',
            ],
            [
                'DASN_SIMEI',
                'DASN-SIMEI',
                'MEI',
                'simples_mei',
                'INTEGRA_MEI',
                'DASN_SIMEI',
                'ANNUAL',
                30,
                ['CONSULTAR_DECLARACAO', 'CONSULTAR_RECIBO'],
                'Declaração Anual do Simples Nacional para MEI.',
            ],
            [
                'DCTFWEB',
                'DCTFWeb',
                'DCTFWEB',
                'dctfweb_mit',
                'INTEGRA_DCTFWEB',
                'DCTFWEB',
                'MONTHLY',
                40,
                ['CONSULTAR_RECIBO', 'CONSULTAR_SITUACAO'],
                'Declaração de Débitos e Créditos Tributários Federais via Web.',
            ],
            [
                'MIT',
                'MIT',
                'MIT',
                'dctfweb_mit',
                'INTEGRA_DCTFWEB',
                'MIT',
                'MONTHLY',
                50,
                ['CONSULTAR_SITUACAO'],
                'Módulo de Inclusão de Tributos (cobertura parcial).',
            ],
        ];

        $defIds = [];
        foreach ($definitions as [$code, $name, $cat, $module, $system, $service, $gran, $sort, $ops, $desc]) {
            $id = DB::table('tax_obligation_definitions')->insertGetId([
                'code' => $code,
                'name' => $name,
                'description' => $desc,
                'fiscal_category_code' => $cat,
                'module_key' => $module,
                'system_code' => $system,
                'service_code' => $service,
                'period_granularity' => $gran,
                'default_timezone' => $tz,
                'is_active' => true,
                'sort_order' => $sort,
                'supported_operations' => json_encode($ops, JSON_UNESCAPED_UNICODE),
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $defIds[$code] = $id;
        }

        // Versões v1 + regras de regime
        $versionSpecs = [
            'PGDAS_D' => [
                'default' => 'NOT_APPLICABLE',
                'basis' => 'PGDAS-D aplica-se a optantes do Simples Nacional (exceto MEI/SIMEI).',
                'source' => 'RFB/INTEGRA_SN',
                'regimes' => [
                    ['SIMPLES_NACIONAL', 'APPLICABLE', 'Optante SN não-MEI.'],
                    ['MEI', 'NOT_APPLICABLE', 'MEI usa DASN-SIMEI/PGMEI, não PGDAS-D.'],
                    ['LUCRO_PRESUMIDO', 'NOT_APPLICABLE', 'Fora do SN.'],
                    ['LUCRO_REAL', 'NOT_APPLICABLE', 'Fora do SN.'],
                    ['UNKNOWN', 'UNKNOWN', 'Regime não confirmado — sem pendência presumida.'],
                ],
            ],
            'DEFIS' => [
                'default' => 'NOT_APPLICABLE',
                'basis' => 'DEFIS anual para optantes do Simples Nacional (exceto MEI).',
                'source' => 'RFB/INTEGRA_SN',
                'regimes' => [
                    ['SIMPLES_NACIONAL', 'APPLICABLE', 'Optante SN não-MEI.'],
                    ['MEI', 'NOT_APPLICABLE', 'MEI não entrega DEFIS.'],
                    ['LUCRO_PRESUMIDO', 'NOT_APPLICABLE', 'Fora do SN.'],
                    ['LUCRO_REAL', 'NOT_APPLICABLE', 'Fora do SN.'],
                    ['UNKNOWN', 'UNKNOWN', 'Regime não confirmado.'],
                ],
            ],
            'DASN_SIMEI' => [
                'default' => 'NOT_APPLICABLE',
                'basis' => 'DASN-SIMEI anual exclusiva de MEI/SIMEI.',
                'source' => 'RFB/INTEGRA_MEI',
                'regimes' => [
                    ['MEI', 'APPLICABLE', 'Optante SIMEI.'],
                    ['SIMPLES_NACIONAL', 'NOT_APPLICABLE', 'SN não-MEI usa DEFIS/PGDAS-D.'],
                    ['LUCRO_PRESUMIDO', 'NOT_APPLICABLE', 'Fora do MEI.'],
                    ['LUCRO_REAL', 'NOT_APPLICABLE', 'Fora do MEI.'],
                    ['UNKNOWN', 'UNKNOWN', 'Regime não confirmado.'],
                ],
            ],
            'DCTFWEB' => [
                'default' => 'UNKNOWN',
                'basis' => 'DCTFWeb depende de vínculo e obrigações previdenciárias/RFB; regime isolado não basta.',
                'source' => 'RFB/INTEGRA_DCTFWEB',
                'regimes' => [
                    ['LUCRO_REAL', 'APPLICABLE', 'Regra base: contribuinte em Lucro Real tipicamente obrigado.'],
                    ['LUCRO_PRESUMIDO', 'APPLICABLE', 'Regra base: Lucro Presumido tipicamente obrigado.'],
                    ['SIMPLES_NACIONAL', 'UNKNOWN', 'Pode haver DCTFWeb em cenários específicos — exige fonte.'],
                    ['MEI', 'NOT_APPLICABLE', 'MEI não entrega DCTFWeb.'],
                    ['UNKNOWN', 'UNKNOWN', 'Sem evidência de enquadramento.'],
                ],
            ],
            'MIT' => [
                'default' => 'UNSUPPORTED',
                'basis' => 'MIT com cobertura parcial; aplicabilidade plena não suportada no MVP.',
                'source' => 'RFB/INTEGRA_DCTFWEB',
                'regimes' => [
                    ['LUCRO_REAL', 'UNSUPPORTED', 'Cobertura parcial — não afirmar aplicabilidade plena.'],
                    ['LUCRO_PRESUMIDO', 'UNSUPPORTED', 'Cobertura parcial.'],
                    ['SIMPLES_NACIONAL', 'UNSUPPORTED', 'Cobertura parcial.'],
                    ['MEI', 'NOT_APPLICABLE', 'MEI fora do escopo MIT.'],
                    ['UNKNOWN', 'UNKNOWN', 'Sem evidência.'],
                ],
            ],
        ];

        $versionIds = [];
        foreach ($versionSpecs as $code => $spec) {
            $vid = DB::table('tax_obligation_versions')->insertGetId([
                'obligation_definition_id' => $defIds[$code],
                'version' => 1,
                'rule_key' => $code.'_V1',
                'default_applicability' => $spec['default'],
                'rule_basis' => $spec['basis'],
                'source_ref' => $spec['source'],
                'timezone' => $tz,
                'effective_from' => $now,
                'effective_to' => null,
                'is_current' => true,
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $versionIds[$code] = $vid;

            foreach ($spec['regimes'] as [$regime, $appl, $basis]) {
                DB::table('tax_obligation_regime_rules')->insert([
                    'obligation_version_id' => $vid,
                    'tax_regime' => $regime,
                    'applicability' => $appl,
                    'rule_basis' => $basis,
                    'priority' => 100,
                    'metadata' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Calendário nacional v1 (corrente)
        $calV1 = DB::table('tax_deadline_calendar_versions')->insertGetId([
            'code' => 'RFB_NATIONAL',
            'version' => 1,
            'label' => 'Calendário RFB nacional v1',
            'timezone' => $tz,
            'effective_from' => $now->copy()->subYears(5),
            'effective_to' => null,
            'is_current' => true,
            'source_ref' => 'RFB',
            'notes' => 'Prazos base simplificados para monitoramento (dia fixo; sem feriados bancários no MVP).',
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Regras de prazo v1
        $deadlineRules = [
            // obligation, gran, due_day, month_offset, fixed_month, fixed_day
            ['PGDAS_D', 'MONTHLY', 20, 1, null, null], // dia 20 do mês seguinte
            ['DEFIS', 'ANNUAL', null, 0, 3, 31], // 31/03 do ano seguinte (approx)
            ['DASN_SIMEI', 'ANNUAL', null, 0, 5, 31], // 31/05 do ano seguinte
            ['DCTFWEB', 'MONTHLY', 15, 1, null, null], // dia 15 do mês seguinte
            ['MIT', 'MONTHLY', 15, 1, null, null],
        ];

        foreach ($deadlineRules as [$code, $gran, $dueDay, $offset, $fixedMonth, $fixedDay]) {
            DB::table('tax_deadline_rules')->insert([
                'calendar_version_id' => $calV1,
                'obligation_definition_id' => $defIds[$code],
                'period_granularity' => $gran,
                'due_day' => $dueDay,
                'due_month_offset' => $offset,
                'fixed_due_month' => $fixedMonth,
                'fixed_due_day' => $fixedDay,
                'business_day_adjustment' => 'NONE',
                'timezone' => $tz,
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};

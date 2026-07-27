<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Services\Authorization\SystemTenantPermissionProfiles;
use App\Services\Serpro\Catalog\OfficialServiceCatalogImporter;
use App\Services\Serpro\Usage\ContractPriceTableImporter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Catálogos estáveis necessários em qualquer ambiente.
 *
 * É idempotente e deliberadamente separado das migrations de estrutura.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $this->seedPlatformSettings();
            $this->seedFiscalCategories();
            $this->seedTaxObligations();

            $profiles = app(SystemTenantPermissionProfiles::class);
            Tenant::query()->orderBy('id')->each(
                static fn (Tenant $tenant) => $profiles->ensure($tenant),
            );
        });

        $catalog = app(OfficialServiceCatalogImporter::class)->import();
        if (! $catalog['valid']) {
            throw new RuntimeException(
                'Catálogo oficial SERPRO inválido: '.implode('; ', $catalog['errors']),
            );
        }

        app(ContractPriceTableImporter::class)->importFromFile();
    }

    private function seedPlatformSettings(): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['id' => 1],
            [
                'organization_name' => 'KontiveHub',
                'onboarding_completed_at' => null,
                'onboarded_by_user_id' => null,
                'primary_tenant_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function seedFiscalCategories(): void
    {
        $rows = [
            ['SIMPLES_NACIONAL', 'Simples Nacional', 'simples_mei', 'FULL', 'INTEGRA_SN', 'PGDASD', 10],
            ['MEI', 'MEI / SIMEI', 'simples_mei', 'FULL', 'INTEGRA_MEI', 'PGMEI', 20],
            ['DCTFWEB', 'DCTFWeb', 'dctfweb', 'FULL', 'INTEGRA_DCTFWEB', 'DCTFWEB', 30],
            ['MIT', 'MIT', 'dctfweb', 'PARTIAL', 'INTEGRA_DCTFWEB', 'MIT', 40],
            ['PARCELAMENTOS', 'Parcelamentos', 'installments', 'FULL', 'INTEGRA_PARCELAMENTO', 'PARCELAMENTO', 50],
            ['SITFIS', 'Situação Fiscal (SITFIS)', 'sitfis', 'FULL', 'INTEGRA_SITFIS', 'SITFIS', 60],
            ['CAIXA_POSTAL', 'Caixa Postal / DTE', 'mailbox', 'FULL', 'INTEGRA_CAIXAPOSTAL', 'MENSAGEM', 70],
            ['DECLARACOES', 'Declarações auxiliares', 'declarations', 'PARTIAL', 'INTEGRA_CONTADOR', 'DECLARACAO', 80],
            ['GUIAS', 'Guias / Pagamentos', 'guides', 'PARTIAL', 'INTEGRA_PAGAMENTO', 'GUIA', 90],
            ['FGTS', 'FGTS (parcial eSocial)', 'fgts', 'PARTIAL', 'ESOCIAL', 'FGTS', 100],
        ];

        foreach ($rows as [$code, $name, $module, $coverage, $system, $service, $sort]) {
            DB::table('fiscal_categories')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'module_key' => $module,
                    'default_coverage' => $coverage,
                    'default_mutability' => 'READ_ONLY',
                    'system_code' => $system,
                    'service_code' => $service,
                    'is_active' => true,
                    'sort_order' => $sort,
                    'description' => null,
                    'metadata' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    private function seedTaxObligations(): void
    {
        $now = now();
        $timezone = 'America/Sao_Paulo';
        $definitions = [
            ['PGDAS_D', 'PGDAS-D', 'SIMPLES_NACIONAL', 'simples_mei', 'INTEGRA_SN', 'PGDASD', 'MONTHLY', 10, ['CONSULTAR_DECLARACAO', 'CONSULTAR_RECIBO'], 'Declaração mensal do Simples Nacional (PGDAS-D).'],
            ['DEFIS', 'DEFIS', 'SIMPLES_NACIONAL', 'simples_mei', 'INTEGRA_SN', 'DEFIS', 'ANNUAL', 20, ['CONSULTAR'], 'Declaração de Informações Socioeconômicas e Fiscais.'],
            ['DASN_SIMEI', 'DASN-SIMEI', 'MEI', 'simples_mei', 'INTEGRA_MEI', 'DASN_SIMEI', 'ANNUAL', 30, ['CONSULTAR_DECLARACAO', 'CONSULTAR_RECIBO'], 'Declaração anual do Simples Nacional para MEI.'],
            ['DCTFWEB', 'DCTFWeb', 'DCTFWEB', 'dctfweb', 'INTEGRA_DCTFWEB', 'DCTFWEB', 'MONTHLY', 40, ['CONSULTAR_RECIBO', 'CONSULTAR_SITUACAO'], 'Declaração de Débitos e Créditos Tributários Federais via Web.'],
            ['MIT', 'MIT', 'MIT', 'dctfweb', 'INTEGRA_DCTFWEB', 'MIT', 'MONTHLY', 50, ['CONSULTAR_SITUACAO'], 'Módulo de Inclusão de Tributos.'],
        ];

        $definitionIds = [];
        foreach ($definitions as [$code, $name, $category, $module, $system, $service, $granularity, $sort, $operations, $description]) {
            DB::table('tax_obligation_definitions')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $name,
                    'description' => $description,
                    'fiscal_category_code' => $category,
                    'module_key' => $module,
                    'system_code' => $system,
                    'service_code' => $service,
                    'period_granularity' => $granularity,
                    'default_timezone' => $timezone,
                    'is_active' => true,
                    'sort_order' => $sort,
                    'supported_operations' => json_encode($operations, JSON_THROW_ON_ERROR),
                    'metadata' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            $definitionIds[$code] = (int) DB::table('tax_obligation_definitions')
                ->where('code', $code)
                ->value('id');
        }

        $versions = [
            'PGDAS_D' => ['NOT_APPLICABLE', 'PGDAS-D aplica-se a optantes do Simples Nacional, exceto MEI.', 'RFB/INTEGRA_SN', [
                ['SIMPLES_NACIONAL', 'APPLICABLE', 'Optante SN não-MEI.'],
                ['MEI', 'NOT_APPLICABLE', 'MEI usa DASN-SIMEI/PGMEI.'],
                ['LUCRO_PRESUMIDO', 'NOT_APPLICABLE', 'Fora do Simples Nacional.'],
                ['LUCRO_REAL', 'NOT_APPLICABLE', 'Fora do Simples Nacional.'],
                ['UNKNOWN', 'UNKNOWN', 'Regime não confirmado.'],
            ]],
            'DEFIS' => ['NOT_APPLICABLE', 'DEFIS anual para optantes do Simples Nacional, exceto MEI.', 'RFB/INTEGRA_SN', [
                ['SIMPLES_NACIONAL', 'APPLICABLE', 'Optante SN não-MEI.'],
                ['MEI', 'NOT_APPLICABLE', 'MEI não entrega DEFIS.'],
                ['LUCRO_PRESUMIDO', 'NOT_APPLICABLE', 'Fora do Simples Nacional.'],
                ['LUCRO_REAL', 'NOT_APPLICABLE', 'Fora do Simples Nacional.'],
                ['UNKNOWN', 'UNKNOWN', 'Regime não confirmado.'],
            ]],
            'DASN_SIMEI' => ['NOT_APPLICABLE', 'DASN-SIMEI anual exclusiva de MEI.', 'RFB/INTEGRA_MEI', [
                ['MEI', 'APPLICABLE', 'Optante SIMEI.'],
                ['SIMPLES_NACIONAL', 'NOT_APPLICABLE', 'SN não-MEI usa DEFIS/PGDAS-D.'],
                ['LUCRO_PRESUMIDO', 'NOT_APPLICABLE', 'Fora do MEI.'],
                ['LUCRO_REAL', 'NOT_APPLICABLE', 'Fora do MEI.'],
                ['UNKNOWN', 'UNKNOWN', 'Regime não confirmado.'],
            ]],
            'DCTFWEB' => ['UNKNOWN', 'DCTFWeb depende de vínculos e obrigações previdenciárias.', 'RFB/INTEGRA_DCTFWEB', [
                ['LUCRO_REAL', 'APPLICABLE', 'Regra base para Lucro Real.'],
                ['LUCRO_PRESUMIDO', 'APPLICABLE', 'Regra base para Lucro Presumido.'],
                ['SIMPLES_NACIONAL', 'UNKNOWN', 'Exige confirmação na fonte oficial.'],
                ['MEI', 'NOT_APPLICABLE', 'MEI não entrega DCTFWeb.'],
                ['UNKNOWN', 'UNKNOWN', 'Sem evidência de enquadramento.'],
            ]],
            'MIT' => ['UNSUPPORTED', 'Aplicabilidade plena do MIT exige confirmação oficial.', 'RFB/INTEGRA_DCTFWEB', [
                ['LUCRO_REAL', 'UNSUPPORTED', 'Exige confirmação oficial.'],
                ['LUCRO_PRESUMIDO', 'UNSUPPORTED', 'Exige confirmação oficial.'],
                ['SIMPLES_NACIONAL', 'UNSUPPORTED', 'Exige confirmação oficial.'],
                ['MEI', 'NOT_APPLICABLE', 'MEI fora do escopo MIT.'],
                ['UNKNOWN', 'UNKNOWN', 'Sem evidência.'],
            ]],
        ];

        foreach ($versions as $code => [$default, $basis, $source, $regimes]) {
            $definitionId = $definitionIds[$code];
            DB::table('tax_obligation_versions')->updateOrInsert(
                ['obligation_definition_id' => $definitionId, 'version' => 1],
                [
                    'rule_key' => $code.'_V1',
                    'default_applicability' => $default,
                    'rule_basis' => $basis,
                    'source_ref' => $source,
                    'timezone' => $timezone,
                    'effective_from' => $now,
                    'effective_to' => null,
                    'is_current' => true,
                    'metadata' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
            $versionId = (int) DB::table('tax_obligation_versions')
                ->where('obligation_definition_id', $definitionId)
                ->where('version', 1)
                ->value('id');

            foreach ($regimes as [$regime, $applicability, $ruleBasis]) {
                DB::table('tax_obligation_regime_rules')->updateOrInsert(
                    ['obligation_version_id' => $versionId, 'tax_regime' => $regime],
                    [
                        'applicability' => $applicability,
                        'rule_basis' => $ruleBasis,
                        'priority' => 100,
                        'metadata' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                );
            }
        }

        DB::table('tax_deadline_calendar_versions')->updateOrInsert(
            ['code' => 'RFB_NATIONAL', 'version' => 1],
            [
                'label' => 'Calendário RFB nacional',
                'timezone' => $timezone,
                'effective_from' => $now->copy()->subYears(5),
                'effective_to' => null,
                'is_current' => true,
                'source_ref' => 'RFB',
                'notes' => 'Prazos-base para monitoramento.',
                'metadata' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
        $calendarId = (int) DB::table('tax_deadline_calendar_versions')
            ->where('code', 'RFB_NATIONAL')
            ->where('version', 1)
            ->value('id');

        $deadlines = [
            ['PGDAS_D', 'MONTHLY', 20, 1, null, null, 'NEXT_BUSINESS_DAY'],
            ['DEFIS', 'ANNUAL', null, 0, 3, 31, 'NONE'],
            ['DASN_SIMEI', 'ANNUAL', null, 0, 5, 31, 'NONE'],
            ['DCTFWEB', 'MONTHLY', 15, 1, null, null, 'NONE'],
            ['MIT', 'MONTHLY', 15, 1, null, null, 'NONE'],
        ];
        foreach ($deadlines as [$code, $granularity, $dueDay, $offset, $fixedMonth, $fixedDay, $adjustment]) {
            DB::table('tax_deadline_rules')->updateOrInsert(
                [
                    'calendar_version_id' => $calendarId,
                    'obligation_definition_id' => $definitionIds[$code],
                ],
                [
                    'period_granularity' => $granularity,
                    'due_day' => $dueDay,
                    'due_month_offset' => $offset,
                    'fixed_due_month' => $fixedMonth,
                    'fixed_due_day' => $fixedDay,
                    'business_day_adjustment' => $adjustment,
                    'timezone' => $timezone,
                    'metadata' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }
}

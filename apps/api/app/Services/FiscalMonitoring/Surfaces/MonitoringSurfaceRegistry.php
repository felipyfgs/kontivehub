<?php

namespace App\Services\FiscalMonitoring\Surfaces;

use App\Enums\FiscalModuleKey;
use App\Enums\FiscalOperationClass;
use App\Enums\MonitoringChannel;
use App\Enums\MonitoringDocumentPolicy;
use App\Enums\MonitoringOfficialStateSummary;
use App\Enums\MonitoringResultKind;
use App\Enums\SerproOfficialState;
use App\Enums\SerproPlatformSupport;
use App\Services\Serpro\Catalog\OfficialServiceCatalogManifest;
use InvalidArgumentException;

/**
 * Registro tipado de todas as superfícies de page-payload-matrix.md.
 */
final class MonitoringSurfaceRegistry
{
    /** @var array<string, MonitoringSurfaceContract>|null */
    private ?array $contracts = null;

    private ?MonitoringCatalogMetadata $catalogMetadata = null;

    public function __construct(
        private readonly OfficialServiceCatalogManifest $catalog,
        private readonly MonitoringSurfaceCatalogValidator $validator,
        private readonly MonitoringActionMetadataRegistry $actionMetadata,
    ) {}

    /**
     * @return array<string, MonitoringSurfaceContract>
     */
    public function all(): array
    {
        return $this->ensureLoaded();
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->ensureLoaded());
    }

    public function get(string $surfaceKey): MonitoringSurfaceContract
    {
        $all = $this->ensureLoaded();
        if (! isset($all[$surfaceKey])) {
            throw new InvalidArgumentException("surface_key desconhecida: {$surfaceKey}");
        }

        return $all[$surfaceKey];
    }

    public function has(string $surfaceKey): bool
    {
        return isset($this->ensureLoaded()[$surfaceKey]);
    }

    public function metadata(): MonitoringCatalogMetadata
    {
        $this->ensureLoaded();

        return $this->catalogMetadata
            ?? throw new InvalidArgumentException('Metadados do catálogo não carregados.');
    }

    /**
     * Resolve a superfície a partir do módulo de portfolio e submodule opcional.
     */
    public function resolveForModule(FiscalModuleKey $module, ?string $submodule = null): MonitoringSurfaceContract
    {
        $sub = $submodule !== null ? strtoupper(trim($submodule)) : null;

        $key = match ($module) {
            FiscalModuleKey::Dashboard => 'monitoring_dashboard',
            FiscalModuleKey::SimplesMei => match ($sub) {
                null, 'PGDASD' => 'simples_mei_pgdasd',
                'PGMEI' => 'simples_mei_pgmei',
                default => throw new InvalidArgumentException("Submódulo Simples / MEI não disponível: {$sub}"),
            },
            FiscalModuleKey::Dctfweb => match ($sub) {
                'MIT' => 'mit',
                'DCTFWEB', 'DCTF' => 'dctfweb',
                default => 'dctfweb',
            },
            FiscalModuleKey::Fgts => 'fgts',
            FiscalModuleKey::Installments => 'installments',
            FiscalModuleKey::Sitfis => 'sitfis',
            FiscalModuleKey::Mailbox => 'mailbox_list',
            FiscalModuleKey::Declarations => 'declarations',
            FiscalModuleKey::Guides => 'guides',
            FiscalModuleKey::Registrations => 'registrations',
            FiscalModuleKey::TaxProcesses => 'tax_processes',
        };

        return $this->get($key);
    }

    /**
     * @return array<string, MonitoringSurfaceContract>
     */
    private function ensureLoaded(): array
    {
        if ($this->contracts !== null) {
            return $this->contracts;
        }

        $manifest = $this->catalog->load();
        $index = $this->validator->indexByOperationKey($manifest);
        $trialScenarios = config('serpro.environments.TRIAL.scenarios', []);
        $trialScenarios = is_array($trialScenarios) ? $trialScenarios : [];
        $contracts = $this->buildContracts($index, $trialScenarios);
        $this->validator->assertValid($contracts, $manifest);
        $this->catalogMetadata = new MonitoringCatalogMetadata(
            manifestVersion: (string) $manifest['manifest_version'],
            verifiedAt: (string) $manifest['verified_at'],
            catalogOperations: count($manifest['entries']),
            trialScenarios: count($trialScenarios),
        );
        $this->contracts = $contracts;

        return $this->contracts;
    }

    /**
     * @param  array<string, array<string, mixed>>  $index
     * @param  array<string, mixed>  $trialScenarios
     * @return array<string, MonitoringSurfaceContract>
     */
    private function buildContracts(array $index, array $trialScenarios): array
    {
        $list = [
            new MonitoringSurfaceContract(
                surfaceKey: 'monitoring_dashboard',
                routePattern: '/monitoring',
                responsibility: 'Priorizar problemas acionáveis da carteira, sem criar conclusão fiscal nova',
                channel: MonitoringChannel::Aggregate,
                operationKeys: [],
                officialState: MonitoringOfficialStateSummary::NotApplicable,
                resultKind: MonitoringResultKind::Aggregate,
                allowsDocument: false,
                documentPolicy: MonitoringDocumentPolicy::Never,
                sourceLabel: 'Dashboard de monitoramento',
            ),
            new MonitoringSurfaceContract(
                surfaceKey: 'simples_mei_pgdasd',
                routePattern: '/monitoring/simples-mei',
                responsibility: 'Declarações PGDAS-D e seus documentos',
                channel: MonitoringChannel::Integra,
                operationKeys: [
                    // Superfície de monitoramento: apenas consultas oficiais 13–16 (sem emissão)
                    'pgdasd.consdeclaracao',
                    'pgdasd.consultimadecrec',
                    'pgdasd.consdecrec',
                    'pgdasd.consextrato',
                    'defis.consdeclaracao',
                    'defis.consultimadecrec',
                    'defis.consdecrec',
                    'ccmei.dadosccmei',
                    'ccmei.ccmeisitcadastral',
                    'regimeapuracao.consultaranoscalendarios',
                    'regimeapuracao.consultaropcaoregime',
                    'regimeapuracao.consultarresolucao',
                ],
                officialState: MonitoringOfficialStateSummary::Production,
                resultKind: MonitoringResultKind::Pdf,
                allowsDocument: true,
                documentPolicy: MonitoringDocumentPolicy::WhenArtifact,
                sourceLabel: 'PGDAS-D',
            ),
            new MonitoringSurfaceContract(
                surfaceKey: 'simples_mei_pgmei',
                routePattern: '/monitoring/simples-mei',
                responsibility: 'Dívida ativa do MEI por ano-calendário, sem emitir DAS',
                channel: MonitoringChannel::Integra,
                operationKeys: [
                    'pgmei.dividaativa',
                ],
                officialState: MonitoringOfficialStateSummary::Production,
                resultKind: MonitoringResultKind::Structured,
                allowsDocument: false,
                documentPolicy: MonitoringDocumentPolicy::Never,
                sourceLabel: 'PGMEI',
            ),
            new MonitoringSurfaceContract(
                surfaceKey: 'dctfweb',
                routePattern: '/monitoring/dctfweb',
                responsibility: 'Declaração DCTFWeb e artefatos oficiais, sem misturar MIT',
                channel: MonitoringChannel::Integra,
                operationKeys: [
                    'dctfweb.consrecibo',
                    'dctfweb.consdeccompleta',
                    'dctfweb.consxmldeclaracao',
                    'dctfweb.gerarguia',
                    'dctfweb.gerarguiaandamento',
                ],
                officialState: MonitoringOfficialStateSummary::Production,
                resultKind: MonitoringResultKind::Pdf,
                allowsDocument: true,
                documentPolicy: MonitoringDocumentPolicy::WhenArtifact,
                sourceLabel: 'DCTFWeb',
            ),
            new MonitoringSurfaceContract(
                surfaceKey: 'mit',
                routePattern: '/monitoring/dctfweb',
                responsibility: 'Apurações e encerramento MIT, sem reutilizar colunas da DCTFWeb',
                channel: MonitoringChannel::Integra,
                operationKeys: [
                    'mit.listaapuracoes',
                    'mit.consapuracao',
                    'mit.situacaoenc',
                ],
                officialState: MonitoringOfficialStateSummary::Production,
                resultKind: MonitoringResultKind::Structured,
                allowsDocument: false,
                documentPolicy: MonitoringDocumentPolicy::Never,
                sourceLabel: 'MIT',
            ),
            new MonitoringSurfaceContract(
                surfaceKey: 'fgts',
                routePattern: '/monitoring/fgts',
                responsibility: 'Fechamento e totalizações FGTS/eSocial',
                channel: MonitoringChannel::Esocial,
                operationKeys: [],
                officialState: MonitoringOfficialStateSummary::NotApplicable,
                resultKind: MonitoringResultKind::Structured,
                allowsDocument: false,
                documentPolicy: MonitoringDocumentPolicy::Never,
                sourceLabel: 'FGTS / eSocial',
            ),
            new MonitoringSurfaceContract(
                surfaceKey: 'installments',
                routePattern: '/monitoring/installments',
                responsibility: 'Pedidos, saldo, parcelas, pagamentos e documento da parcela por modalidade',
                channel: MonitoringChannel::Integra,
                operationKeys: $this->installmentProductionKeys($index),
                officialState: MonitoringOfficialStateSummary::Production,
                resultKind: MonitoringResultKind::Pdf,
                allowsDocument: true,
                documentPolicy: MonitoringDocumentPolicy::WhenArtifact,
                sourceLabel: 'Parcelamentos',
            ),
            new MonitoringSurfaceContract(
                surfaceKey: 'sitfis',
                routePattern: '/monitoring/sitfis',
                responsibility: 'Estado da consulta assíncrona e relatório oficial',
                channel: MonitoringChannel::Integra,
                operationKeys: [
                    'sitfis.solicitar_protocolo',
                    'sitfis.emitir_relatorio',
                ],
                officialState: MonitoringOfficialStateSummary::Production,
                resultKind: MonitoringResultKind::AsyncPdf,
                allowsDocument: true,
                documentPolicy: MonitoringDocumentPolicy::AsyncWhenReady,
                sourceLabel: 'SITFIS — Relatório de Situação Fiscal',
            ),
            new MonitoringSurfaceContract(
                surfaceKey: 'mailbox_list',
                routePattern: '/monitoring/mailbox',
                responsibility: 'Carteira de mensagens e prazos por cliente',
                channel: MonitoringChannel::Integra,
                operationKeys: [
                    'caixa_postal.lista',
                    'caixa_postal.indicador',
                    'dte.consultar',
                ],
                officialState: MonitoringOfficialStateSummary::Production,
                resultKind: MonitoringResultKind::Structured,
                allowsDocument: false,
                documentPolicy: MonitoringDocumentPolicy::Never,
                sourceLabel: 'Caixa Postal',
            ),
            new MonitoringSurfaceContract(
                surfaceKey: 'mailbox_detail',
                routePattern: '/monitoring/mailbox/:id',
                responsibility: 'Conteúdo oficial de uma mensagem específica',
                channel: MonitoringChannel::Integra,
                operationKeys: [
                    'caixa_postal.detalhe',
                ],
                officialState: MonitoringOfficialStateSummary::Production,
                resultKind: MonitoringResultKind::Structured,
                allowsDocument: false,
                documentPolicy: MonitoringDocumentPolicy::Never,
                sourceLabel: 'Caixa Postal — detalhe',
            ),
            new MonitoringSurfaceContract(
                surfaceKey: 'declarations',
                routePattern: '/monitoring/declarations',
                responsibility: 'Agenda e entrega consolidadas, delegando evidência à obrigação de origem',
                channel: MonitoringChannel::Aggregate,
                operationKeys: [],
                officialState: MonitoringOfficialStateSummary::NotApplicable,
                resultKind: MonitoringResultKind::Aggregate,
                allowsDocument: true,
                documentPolicy: MonitoringDocumentPolicy::WhenArtifact,
                sourceLabel: 'Integra Contador + agenda fiscal',
            ),
            new MonitoringSurfaceContract(
                surfaceKey: 'guides',
                routePattern: '/monitoring/guides',
                responsibility: 'Guias/documentos de arrecadação e confirmação oficial de pagamento',
                channel: MonitoringChannel::Aggregate,
                operationKeys: [
                    'pagtoweb.pagamentos',
                    'pagtoweb.comparrecadacao',
                    'sicalc.consolidargerardarf',
                    'sicalc.gerardarfcodbarra',
                    'sicalc.consultaapoioreceitas',
                    'pagtoweb.contaconsdocarrpg',
                ],
                officialState: MonitoringOfficialStateSummary::Production,
                resultKind: MonitoringResultKind::Pdf,
                allowsDocument: true,
                documentPolicy: MonitoringDocumentPolicy::WhenArtifact,
                sourceLabel: 'Guias',
            ),
            new MonitoringSurfaceContract(
                surfaceKey: 'registrations',
                routePattern: '/monitoring/registrations',
                responsibility: 'Vínculos cadastrais PNR/Redesim',
                channel: MonitoringChannel::Integra,
                operationKeys: [
                    'pnr_contador.consultar_vinculos',
                ],
                officialState: MonitoringOfficialStateSummary::Production,
                resultKind: MonitoringResultKind::Structured,
                allowsDocument: false,
                documentPolicy: MonitoringDocumentPolicy::Never,
                sourceLabel: 'Cadastro e vínculos',
            ),
            new MonitoringSurfaceContract(
                surfaceKey: 'tax_processes',
                routePattern: '/monitoring/tax-processes',
                responsibility: 'Processos do contribuinte',
                channel: MonitoringChannel::Integra,
                operationKeys: [
                    'eprocesso.consultar_por_interessado',
                ],
                officialState: MonitoringOfficialStateSummary::Production,
                resultKind: MonitoringResultKind::Structured,
                allowsDocument: false,
                documentPolicy: MonitoringDocumentPolicy::Never,
                sourceLabel: 'Processos fiscais',
            ),
            new MonitoringSurfaceContract(
                surfaceKey: 'client_detail',
                routePattern: '/monitoring/clients/:clientId',
                responsibility: 'Consolidar módulos de um cliente sem duplicar payload',
                channel: MonitoringChannel::Aggregate,
                operationKeys: [],
                officialState: MonitoringOfficialStateSummary::NotApplicable,
                resultKind: MonitoringResultKind::Aggregate,
                allowsDocument: false,
                documentPolicy: MonitoringDocumentPolicy::Never,
                sourceLabel: 'Detalhe do cliente',
            ),
        ];

        $map = [];
        foreach ($list as $contract) {
            $map[$contract->surfaceKey] = $this->hydrateCapabilities(
                $contract,
                $index,
                $trialScenarios,
            );
        }

        return $map;
    }

    /**
     * @param  array<string, array<string, mixed>>  $index
     * @param  array<string, mixed>  $trialScenarios
     */
    private function hydrateCapabilities(
        MonitoringSurfaceContract $surface,
        array $index,
        array $trialScenarios,
    ): MonitoringSurfaceContract {
        $grouped = [];
        foreach ($surface->operationKeys as $operationKey) {
            $entry = $index[$operationKey];
            [$capabilityKey] = explode('.', $operationKey, 2);
            $handler = $this->actionMetadata->handlerFor($operationKey);
            $isMutating = (bool) ($entry['is_mutating'] ?? true);
            $operationClass = $this->operationClass($operationKey, $isMutating);
            $officialState = (string) ($entry['official_state'] ?? 'UNKNOWN');
            $platformSupport = (string) ($entry['platform_support'] ?? '');
            $available = $operationClass === FiscalOperationClass::Read
                && $officialState === SerproOfficialState::Production->value
                && in_array($platformSupport, [
                    SerproPlatformSupport::Implemented->value,
                    SerproPlatformSupport::ProductionValidated->value,
                ], true)
                && $handler !== 'none';

            $grouped[$capabilityKey][] = new MonitoringActionContract(
                actionKey: $capabilityKey.':'.(explode('.', $operationKey, 2)[1] ?? $operationKey),
                operationKey: $operationKey,
                label: (string) ($entry['label'] ?? $operationKey),
                operationClass: $operationClass,
                paramsSchema: $this->actionMetadata->paramsFor($operationKey),
                resultKind: $surface->resultKind,
                documentPolicy: $surface->documentPolicy,
                handler: $handler,
                available: $available,
                officialState: $officialState,
                sourceLabel: $surface->sourceLabel,
                moduleKey: (string) ($entry['monitoring_module'] ?? 'unknown'),
                featureModule: $this->actionMetadata->featureModuleFor(
                    (string) ($entry['monitoring_module'] ?? ''),
                ),
                requiredProxyPowers: $this->requiredProxyPowers($entry),
                runCodes: $this->actionMetadata->runCodesFor($operationKey),
                async: $surface->resultKind === MonitoringResultKind::AsyncPdf,
                outputFields: $this->publicOutputFields($entry['response_schema']['fields'] ?? []),
                officialRoute: (string) ($entry['route'] ?? ''),
                trialScenarioAvailable: isset($trialScenarios[$operationKey]),
                requestDocumented: (bool) ($entry['request_schema']['documented'] ?? false),
                responseDocumented: (bool) ($entry['response_schema']['documented'] ?? false),
            );
        }

        $capabilities = [];
        foreach ($grouped as $capabilityKey => $actions) {
            $capabilities[] = new MonitoringCapabilityContract(
                capabilityKey: $capabilityKey,
                label: $this->capabilityLabel($capabilityKey),
                actions: $actions,
            );
        }

        return new MonitoringSurfaceContract(
            surfaceKey: $surface->surfaceKey,
            routePattern: $surface->routePattern,
            responsibility: $surface->responsibility,
            channel: $surface->channel,
            operationKeys: $surface->operationKeys,
            officialState: $surface->officialState,
            resultKind: $surface->resultKind,
            allowsDocument: $surface->allowsDocument,
            documentPolicy: $surface->documentPolicy,
            sourceLabel: $surface->sourceLabel,
            capabilityContracts: $capabilities,
        );
    }

    private function operationClass(string $operationKey, bool $isMutating): FiscalOperationClass
    {
        if (! $isMutating) {
            return FiscalOperationClass::Read;
        }

        return preg_match('/(^|\.)(gerar|emitir)/', $operationKey) === 1
            ? FiscalOperationClass::DocumentGeneration
            : FiscalOperationClass::FiscalMutation;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    private function requiredProxyPowers(array $entry): array
    {
        $powers = $entry['required_proxy_powers'] ?? [];
        if (is_array($powers) && $powers !== []) {
            return array_values(array_filter(array_map(
                static fn (mixed $power): string => is_string($power) ? trim($power) : '',
                $powers,
            )));
        }

        $single = trim((string) ($entry['required_proxy_power'] ?? ''));

        return $single === '' ? [] : array_values(array_filter(preg_split('/\s+/', $single) ?: []));
    }

    /** @return list<array{name: string, type: string}> */
    private function publicOutputFields(mixed $fields): array
    {
        if (! is_array($fields)) {
            return [];
        }

        $output = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }
            $name = trim((string) ($field['field'] ?? $field['name'] ?? ''));
            if ($name !== '') {
                $output[$name] = [
                    'name' => $name,
                    'type' => trim((string) ($field['type'] ?? '')),
                ];
            }
        }

        return array_values($output);
    }

    private function capabilityLabel(string $key): string
    {
        return match ($key) {
            'pgdasd' => 'PGDAS-D',
            'pgmei' => 'PGMEI',
            'defis' => 'DEFIS',
            'ccmei' => 'CCMEI',
            'regimeapuracao' => 'Regime de Apuração',
            'dctfweb' => 'DCTFWeb',
            'mit' => 'MIT',
            'sicalc' => 'Sicalc',
            'pagtoweb' => 'PagtoWeb',
            default => strtoupper(str_replace('_', ' ', $key)),
        };
    }

    /**
     * Famílias produtivas de parcelamento (exclui PAEX/SIPADE em prospecção).
     *
     * @param  array<string, array<string, mixed>>  $index
     * @return list<string>
     */
    private function installmentProductionKeys(array $index): array
    {
        $suffixes = [
            'pedidosparc',
            'obterparc',
            'parcelasparagerar',
            'detpagtoparc',
            'gerardas',
        ];
        $prefixes = [
            'parcsn.',
            'parcsn_esp.',
            'pertsn.',
            'relpsn.',
            'parcmei.',
            'parcmei_esp.',
            'pertmei.',
            'relpmei.',
        ];

        $keys = [];
        foreach ($index as $opKey => $entry) {
            if (($entry['official_state'] ?? '') !== SerproOfficialState::Production->value) {
                continue;
            }
            $matchedPrefix = false;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($opKey, $prefix)) {
                    $matchedPrefix = true;
                    break;
                }
            }
            if (! $matchedPrefix) {
                continue;
            }
            foreach ($suffixes as $suffix) {
                if (str_ends_with($opKey, '.'.$suffix)) {
                    $keys[] = $opKey;
                    break;
                }
            }
        }

        sort($keys);

        return $keys;
    }
}

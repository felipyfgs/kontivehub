<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Contracts\FiscalSourceAdapter;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Fiscal\SimplesMei\SimplesMeiOperationDef;
use App\DTO\Serpro\MutationAuthorization;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalSourceProvenance;
use App\Enums\SerproEnvironment;
use App\Models\DefisDeclarationReference;
use App\Models\PgdasdOperation;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdConsDeclaracao13Codec;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdDocumentCodecs;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdPeriod;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdPostConsultService;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiDividaAtiva24Codec;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiPostConsultService;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiYear;
use App\Services\Integra\ContributorCnpjResolver;
use App\Services\Integra\EnsureClientProcuracaoForConsult;
use App\Services\Integra\IntegraEligibilityService;
use App\Services\Integra\OfficeSerproAuthorizationService;
use App\Services\Serpro\CapabilityDriverResolver;
use App\Services\Serpro\Catalog\OperationCoordinateResolver;
use App\Services\Serpro\Catalog\OperationKeyMap;
use App\Services\Serpro\SerproContractService;
use App\Services\Serpro\SerproOperationService;
use App\Support\FeatureFlags;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Adapter genérico por definição do catálogo Simples/MEI.
 */
final class SimplesMeiAdapter implements FiscalSourceAdapter
{
    public function __construct(
        private readonly SimplesMeiOperationDef $definition,
        private readonly IntegraEligibilityService $eligibility,
        private readonly SerproOperationService $operations,
        private readonly SimplesMeiResponseMapper $mapper,
        private readonly SerproContractService $contracts,
        private readonly OfficeSerproAuthorizationService $authorizations,
        private readonly RegimeApplicabilityService $regimeApplicability,
        private readonly ContributorCnpjResolver $contributors,
        private readonly PgdasdConsDeclaracao13Codec $pgdasdCodec13,
        private readonly PgdasdDocumentCodecs $pgdasdDocumentCodecs,
        private readonly PgdasdPostConsultService $pgdasdPostConsult,
        private readonly PgmeiDividaAtiva24Codec $pgmeiCodec24,
        private readonly PgmeiPostConsultService $pgmeiPostConsult,
        private readonly CcmeiPostConsultService $ccmeiPostConsult,
        private readonly CcmeiRegistrationStatusPostConsultService $ccmeiRegistrationStatusPost,
        private readonly RegimeResolutionCodec $regimeResolutionCodec,
        private readonly RegimeResolutionPostConsultService $regimeResolutionPost,
        private readonly DefisDeclarationProjector $defisProjector,
        private readonly DefisLatestDeclarationPostConsultService $defisLatestDeclarationPost,
        private readonly DefisSpecificDeclarationPostConsultService $defisSpecificDeclarationPost,
        private readonly DefisDeclarationReferenceStore $defisReferences,
        private readonly EnsureClientProcuracaoForConsult $procuracaoEnsure,
    ) {}

    public function systemCode(): string
    {
        return $this->definition->systemCode;
    }

    public function serviceCode(): string
    {
        return $this->definition->serviceCode;
    }

    public function operationCode(): string
    {
        return $this->definition->operationCode;
    }

    public function mutability(): FiscalMutability
    {
        return $this->definition->mutability;
    }

    public function coverage(): FiscalCoverage
    {
        return $this->definition->coverage;
    }

    public function moduleKey(): ?string
    {
        return SimplesMeiCatalog::MODULE;
    }

    public function supports(FiscalAdapterRequest $request): bool
    {
        return strcasecmp($request->systemCode, $this->definition->systemCode) === 0
            && strcasecmp($request->serviceCode, $this->definition->serviceCode) === 0
            && strcasecmp($request->operationCode, $this->definition->operationCode) === 0;
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        $def = $this->definition;
        $office = $request->office;
        $client = $request->client;

        // 1. Feature module
        if (! FeatureFlags::isModuleEnabled(SimplesMeiCatalog::MODULE, $office->id)
            && ! (bool) config('fiscal_monitoring.enabled', false)) {
            return FiscalAdapterResult::blocked('Módulo simples_mei desabilitado.', 'FEATURE_DISABLED');
        }

        // 2. Mutantes (transmissão / emissão) — bloqueio no piloto
        if ($def->mutability->isMutating()) {
            $mutatingOk = FeatureFlags::isMutatingEnabled(SimplesMeiCatalog::MODULE, $office->id)
                && (bool) config('fiscal_monitoring.mutating_enabled', false);

            if (! $mutatingOk) {
                return FiscalAdapterResult::blocked(
                    'Operação mutante desabilitada no piloto somente leitura.',
                    'MUTATING_DISABLED',
                );
            }
        }

        // 3. Regime: não misturar SN e MEI
        $periodKey = $request->competence?->period_key
            ?? (string) ($request->context['period_key'] ?? $request->progress['period_key'] ?? '');
        $regimeCheck = $this->regimeApplicability->assertOperationApplicable(
            $office,
            $client,
            $def,
            $periodKey !== '' ? $periodKey : null,
        );
        if ($regimeCheck !== null) {
            return $regimeCheck;
        }

        // 4. Ambiente + elegibilidade (procuração, termo, catálogo SERPRO)
        $env = SerproEnvironment::tryFrom((string) config('serpro.default_environment', 'TRIAL'))
            ?? SerproEnvironment::Trial;

        // A elegibilidade deve usar as coordenadas oficiais resolvidas do
        // catálogo, e não os aliases internos INTEGRA_SN/PGDASD. Caso
        // contrário, o poder oficial 00146 é comparado ao alias "PGDASD".
        try {
            $operationKey = OperationKeyMap::require(
                null,
                $def->systemCode,
                $def->serviceCode,
                $this->mapExternalOperation($def),
            );
            $coordinates = app(OperationCoordinateResolver::class)->resolveExecutable($operationKey);
        } catch (\Throwable) {
            return FiscalAdapterResult::failed(
                'Falha ao resolver coordenadas oficiais da operação.',
                'CATALOG_COORDINATES_UNAVAILABLE',
            );
        }

        $usesRealDriver = app(CapabilityDriverResolver::class)
            ->forOperationKey($operationKey)
            ->value === 'real';
        $eligibilitySolution = $usesRealDriver ? (string) $coordinates['id_sistema'] : $def->systemCode;
        $eligibilityService = $usesRealDriver ? (string) $coordinates['id_servico'] : $def->serviceCode;
        $eligibilityOperation = $usesRealDriver
            ? (string) $coordinates['id_servico']
            : $this->resolveCatalogOperation($def);

        if ($def->requiredPowers !== []) {
            $ensure = $this->procuracaoEnsure->ensure(
                $office,
                $client,
                $env,
                $def->requiredPowers,
                $request->run->triggered_by !== null ? (int) $request->run->triggered_by : null,
            );
            if (! $ensure['ok']) {
                $code = $ensure['code'] ?? 'PROXY_POWER_MISSING';

                return FiscalAdapterResult::blocked(
                    $ensure['message'] ?? ('Elegibilidade Integra negada: '.$code),
                    $code,
                );
            }
        }

        $elig = $this->eligibility->evaluate(
            $office,
            $client,
            $eligibilitySolution,
            $eligibilityService,
            $eligibilityOperation,
            $env,
            null,
            SimplesMeiCatalog::MODULE,
        );

        if (! $elig->eligible) {
            $code = $elig->primaryCode()->value;

            return FiscalAdapterResult::blocked(
                'Elegibilidade Integra negada: '.$code,
                $code,
            );
        }

        $this->eligibility->touchRateLimit($office->id);

        $contract = $this->contracts->activeFor($env);
        if ($contract === null || ! $contract->isUsable()) {
            return FiscalAdapterResult::blocked('Contrato SERPRO indisponível.', 'CONTRACT_UNAVAILABLE');
        }

        // Autor/contribuinte resolvidos no executor; pre-check de identidade do autor
        $auth = $this->authorizations->getOrCreate($office, $env);
        if (trim((string) ($auth->author_identity ?? '')) === '') {
            return FiscalAdapterResult::blocked('Autor do Pedido não configurado.', 'AUTHOR_IDENTITY_MISSING');
        }
        try {
            $this->contributors->resolve($client);
        } catch (\Throwable) {
            return FiscalAdapterResult::blocked('CNPJ completo do contribuinte não encontrado.', 'CONTRIBUTOR_IDENTITY_MISSING');
        }

        $correlationId = $request->run->correlation_id ?? (string) Str::uuid();
        $idempotencyKey = 'sm:'.$request->run->idempotency_key.':'.$def->operationCode;

        try {
            $payload = $this->buildPayload($request, $periodKey, $operationKey);
        } catch (InvalidArgumentException $e) {
            return FiscalAdapterResult::failed($e->getMessage(), 'INVALID_PAYLOAD');
        } catch (\Throwable $e) {
            return FiscalAdapterResult::failed(
                'Falha ao montar payload da operação.',
                'PAYLOAD_BUILD_ERROR',
            );
        }

        try {
            $response = $this->operations->execute(
                office: $office,
                client: $client,
                operationKey: $operationKey,
                businessData: $payload,
                idempotencyKey: $idempotencyKey,
                correlationId: $correlationId,
                mutationAuth: MutationAuthorization::none(),
                // Operações documentais PGDAS-D precisam associar o retorno
                // binário à run antes do ACK, para o cofre persistir o PDF.
                entityKey: 'fiscal-run:'.$request->run->id,
                module: SimplesMeiCatalog::MODULE,
            );
        } catch (\Throwable) {
            return FiscalAdapterResult::failed(
                'Falha de transporte Integra Contador.',
                'TRANSPORT_ERROR',
            );
        }

        $sourceProvenance = match (true) {
            $response->sourceProvenance === FiscalSourceProvenance::SerproReal->value
                && ! $response->simulated => FiscalSourceProvenance::SerproReal,
            $response->sourceProvenance === FiscalSourceProvenance::SerproTrial->value => FiscalSourceProvenance::SerproTrial,
            default => FiscalSourceProvenance::Unverified,
        };
        $request->run->forceFill(['source_provenance' => $sourceProvenance])->save();

        $result = $this->mapper->map($def, $response, $periodKey);

        // PGDAS-D 13–16: projeção + sanitização documental
        if (strtoupper($def->serviceCode) === 'PGDASD'
            && str_starts_with($operationKey, 'pgdasd.')
            && $result->result->value === 'SUCCESS') {
            $post = $this->pgdasdPostConsult->handle($request, $response, $result, $operationKey);
            $result = $post['result'];
        }

        // PGMEI DIVIDAATIVA24: projeção de dívida ativa (só promove resposta produtiva válida)
        if (strtoupper($def->serviceCode) === 'PGMEI'
            && str_starts_with($operationKey, 'pgmei.')) {
            $post = $this->pgmeiPostConsult->handle($request, $response, $result, $operationKey);
            $result = $post['result'];
        }

        if ($operationKey === CcmeiPostConsultService::OPERATION_KEY) {
            $post = $this->ccmeiPostConsult->handle($request, $response, $result, $operationKey);
            $result = $post['result'];
        }

        if ($operationKey === CcmeiRegistrationStatusPostConsultService::OPERATION_KEY) {
            $post = $this->ccmeiRegistrationStatusPost->handle($request, $response, $result, $operationKey);
            $result = $post['result'];
        }

        if ($operationKey === 'defis.consdeclaracao'
            && $result->result->value === 'SUCCESS'
            && is_array($result->normalized)) {
            $this->defisProjector->projectFromResponse(
                $office,
                $client,
                $response->dados ?? $response->body,
                $request->run->id,
                $sourceProvenance->value,
            );
        }

        if ($operationKey === DefisLatestDeclarationPostConsultService::OPERATION_KEY) {
            $post = $this->defisLatestDeclarationPost->handle($request, $response, $result);
            $result = $post['result'];
        }

        if ($operationKey === DefisSpecificDeclarationPostConsultService::OPERATION_KEY) {
            $post = $this->defisSpecificDeclarationPost->handle($request, $response, $result);
            $result = $post['result'];
        }

        // REGIME 104: Base64 fail-closed + cofre + projeção local (sem mutar 102/103)
        if (strtoupper($def->serviceCode) === 'REGIME_APURACAO'
            && strtoupper($def->operationCode) === 'CONSULTAR_RESOLUCAO') {
            $post = $this->regimeResolutionPost->handle($request, $response, $result, $operationKey);
            $result = $post['result'];
        }

        // Projeções laterais (regime / guia) sem alterar evidência
        if ($result->result->value === 'SUCCESS' && is_array($result->normalized)) {
            if (strtoupper($def->serviceCode) === 'REGIME_APURACAO') {
                if (strtoupper($def->operationCode) === 'CONSULTAR_ANOS_CALENDARIOS') {
                    $this->regimeApplicability->projectCalendarOptions(
                        $office,
                        $client,
                        is_array($result->normalized['calendar_options'] ?? null)
                            ? $result->normalized['calendar_options']
                            : [],
                        $request->run->id,
                    );
                } elseif (strtoupper($def->operationCode) === 'CONSULTAR') {
                    $option = $result->normalized['calendar_options'][0] ?? null;
                    if (is_array($option)) {
                        $this->regimeApplicability->projectRegimeOption(
                            $office,
                            $client,
                            $option,
                            $request->run->id,
                        );
                    }
                } elseif (strtoupper($def->operationCode) === 'CONSULTAR_RESOLUCAO') {
                    // Projeção já feita no post-consult (evidência + descritor).
                } else {
                    $this->regimeApplicability->projectFromNormalized(
                        $office,
                        $client,
                        $result->normalized,
                        $request->run->id,
                    );
                }
            }

            if (in_array(strtoupper($def->serviceCode), ['PGDASD', 'DEFIS', 'PGMEI', 'DASN_SIMEI'], true)
                && strtoupper($def->operationCode) !== 'TRANSMITIR'
                && strtoupper($def->operationCode) !== 'GERAR_DAS'
                && ! in_array($operationKey, [
                    'defis.consdeclaracao',
                    DefisLatestDeclarationPostConsultService::OPERATION_KEY,
                    DefisSpecificDeclarationPostConsultService::OPERATION_KEY,
                ], true)) {
                $this->regimeApplicability->projectCompetenceSituation(
                    $office,
                    $client,
                    $def,
                    $periodKey,
                    $result->situation,
                    $result->coverage,
                    $result->normalized,
                );
            }
        }

        return $result;
    }

    /**
     * MONITOR mapeia para a consulta principal catalogada no SERPRO.
     */
    private function resolveCatalogOperation(SimplesMeiOperationDef $def): string
    {
        if (! $def->isMonitor) {
            return $def->operationCode;
        }

        return match (strtoupper($def->serviceCode)) {
            'PGDASD' => 'CONSULTAR_DECLARACAO',
            'DEFIS', 'REGIME_APURACAO', 'PGMEI', 'CCMEI', 'DASN_SIMEI' => 'CONSULTAR',
            default => $def->operationCode,
        };
    }

    private function mapExternalOperation(SimplesMeiOperationDef $def): string
    {
        return $this->resolveCatalogOperation($def);
    }

    /**
     * Payload oficial por operation_key. PGDAS-D 13 usa XOR ano/PA.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(FiscalAdapterRequest $request, string $periodKey, string $operationKey): array
    {
        if ($operationKey === 'defis.consdecrec') {
            $referenceId = $request->context['defis_reference_id'] ?? $request->progress['defis_reference_id'] ?? null;
            if (! is_int($referenceId) && ! (is_string($referenceId) && ctype_digit($referenceId))) {
                throw new InvalidArgumentException('Referência de declaração DEFIS inválida.');
            }
            $reference = DefisDeclarationReference::query()->withoutGlobalScopes()
                ->where('office_id', $request->office->id)->where('client_id', $request->client->id)->find((int) $referenceId);
            if ($reference === null) {
                throw new InvalidArgumentException('Referência de declaração DEFIS indisponível.');
            }

            return ['idDefis' => $this->defisReferences->read($reference, $request->office)];
        }

        if ($operationKey === 'regimeapuracao.consultaranoscalendarios') {
            return [];
        }

        if ($operationKey === RegimeResolutionCodec::OPERATION_KEY) {
            return $this->buildRegimeResolutionPayload($request, $periodKey);
        }

        if ($operationKey === DefisLatestDeclarationPostConsultService::OPERATION_KEY) {
            $year = $request->context['calendar_year'] ?? $request->progress['calendar_year'] ?? substr($periodKey, 0, 4);

            return ['ano' => (new DefisLatestDeclarationCodec)->assertCalendarYear($year)];
        }

        if ($operationKey === RegimeOptionCodec::OPERATION_KEY) {
            return (new RegimeOptionCodec)->buildPayload(substr($periodKey, 0, 4));
        }

        if (str_starts_with($operationKey, 'pgdasd.')) {
            return $this->buildPgdasdPayload($request, $periodKey, $operationKey);
        }

        if ($operationKey === 'pgmei.dividaativa') {
            return $this->buildPgmeiDividaAtivaPayload($request, $periodKey);
        }

        $payload = [
            'periodo' => $periodKey,
            'competencia' => $periodKey,
        ];

        foreach (['ano', 'year', 'scenario', 'force_status'] as $key) {
            if (array_key_exists($key, $request->context)) {
                $payload[$key] = $request->context[$key];
            } elseif (array_key_exists($key, $request->progress)) {
                $payload[$key] = $request->progress[$key];
            }
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPgdasdPayload(FiscalAdapterRequest $request, string $periodKey, string $operationKey): array
    {
        $ctx = $request->context;
        $progress = $request->progress;

        return match ($operationKey) {
            'pgdasd.consdeclaracao' => $this->buildConsDeclaracao13Payload($request, $periodKey),
            'pgdasd.consultimadecrec' => $this->pgdasdDocumentCodecs->buildPayload14(
                (string) ($ctx['periodoApuracao']
                    ?? $progress['periodo_apuracao']
                    ?? ($periodKey !== '' ? PgdasdPeriod::periodoApuracaoFromPeriodKey($periodKey) : ''))
            ),
            'pgdasd.consdecrec' => $this->buildConsDecRec15Payload($request, $periodKey),
            'pgdasd.consextrato' => $this->pgdasdDocumentCodecs->buildPayload16(
                (string) ($ctx['numeroDas'] ?? $progress['numero_das'] ?? '')
            ),
            default => [
                'periodo' => $periodKey,
                'competencia' => $periodKey,
            ],
        };
    }

    /**
     * @return array<string, string>
     */
    private function buildConsDeclaracao13Payload(FiscalAdapterRequest $request, string $periodKey): array
    {
        $ctx = $request->context;
        $progress = $request->progress;

        $ano = isset($ctx['anoCalendario'])
            ? (string) $ctx['anoCalendario']
            : (isset($progress['ano_calendario']) ? (string) $progress['ano_calendario'] : null);
        $pa = isset($ctx['periodoApuracao'])
            ? (string) $ctx['periodoApuracao']
            : (isset($progress['periodo_apuracao']) ? (string) $progress['periodo_apuracao'] : null);

        // Scheduler congela PA esperado e consulta o ANO do PA (serviço 13 anual).
        if (($ano === null || $ano === '') && ($pa === null || $pa === '')) {
            $expected = $progress['expected_periodo_apuracao']
                ?? $ctx['expected_periodo_apuracao']
                ?? null;
            if (is_string($expected) && preg_match('/^\d{6}$/', $expected) === 1) {
                $ano = substr($expected, 0, 4);
            } elseif ($periodKey !== '') {
                try {
                    $parsed = PgdasdPeriod::parse($periodKey);
                    $ano = PgdasdPeriod::yearFromPa($parsed);
                } catch (\Throwable) {
                    $ano = null;
                }
            } else {
                $tz = (string) ($request->office->timezone ?? 'America/Sao_Paulo') ?: 'America/Sao_Paulo';
                $expectedPa = PgdasdPeriod::expectedPa(null, $tz);
                $ano = PgdasdPeriod::yearFromPa($expectedPa);
            }
        }

        // Se ambos vierem, rejeita (XOR). Se só PA, usa PA. Se só ano, usa ano.
        return $this->pgdasdCodec13->buildPayload(
            ($ano !== null && $ano !== '') ? $ano : null,
            ($pa !== null && $pa !== '') ? $pa : null,
        );
    }

    /**
     * O serviço 15 somente pode consultar uma declaração previamente observada
     * pelo serviço 13 no mesmo tenant/cliente. O PA informado precisa coincidir.
     *
     * @return array{numeroDeclaracao: string}
     */
    private function buildConsDecRec15Payload(FiscalAdapterRequest $request, string $periodKey): array
    {
        $ctx = $request->context;
        $progress = $request->progress;
        $declarationNumber = trim((string) (
            $ctx['numeroDeclaracao']
            ?? $progress['numero_declaracao']
            ?? ''
        ));

        if ($declarationNumber === '') {
            throw new InvalidArgumentException('Número da declaração é obrigatório para o serviço 15.');
        }

        $observed = PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $request->office->id)
            ->where('client_id', $request->client->id)
            ->where('kind', 'DECLARATION')
            ->where('declaration_number', $declarationNumber)
            ->orderByDesc('transmitted_at')
            ->orderByDesc('id')
            ->first();

        if ($observed === null) {
            throw new InvalidArgumentException(
                'Declaração não observada em consulta válida do serviço 13 para este cliente.'
            );
        }

        $providedPeriod = trim((string) (
            $request->competence?->period_key
            ?? $ctx['period_key']
            ?? $progress['period_key']
            ?? ($periodKey !== '' ? $periodKey : '')
        ));
        if ($providedPeriod !== '' && $providedPeriod !== (string) $observed->period_key) {
            throw new InvalidArgumentException('O PA informado não corresponde à declaração observada.');
        }

        return $this->pgdasdDocumentCodecs->buildPayload15($declarationNumber);
    }

    /**
     * Payload oficial CONSULTARRESOLUCAO104: exatamente um anoCalendario.
     *
     * @return array{anoCalendario: int}
     */
    private function buildRegimeResolutionPayload(FiscalAdapterRequest $request, string $periodKey): array
    {
        $ctx = $request->context;
        $progress = $request->progress;

        $raw = $ctx['anoCalendario']
            ?? $ctx['ano_calendario']
            ?? $ctx['year']
            ?? $progress['ano_calendario']
            ?? $progress['anoCalendario']
            ?? $progress['year']
            ?? $progress['period_key']
            ?? ($periodKey !== '' ? $periodKey : null);

        if ($raw === null || $raw === '') {
            throw new InvalidArgumentException(
                'anoCalendario é obrigatório para CONSULTARRESOLUCAO104.'
            );
        }

        return $this->regimeResolutionCodec->buildPayload((string) $raw);
    }

    /**
     * Payload oficial DIVIDAATIVA24: exatamente um anoCalendario AAAA.
     *
     * @return array{anoCalendario: string}
     */
    private function buildPgmeiDividaAtivaPayload(FiscalAdapterRequest $request, string $periodKey): array
    {
        $ctx = $request->context;
        $progress = $request->progress;

        $raw = $ctx['anoCalendario']
            ?? $ctx['ano_calendario']
            ?? $progress['ano_calendario']
            ?? $progress['anoCalendario']
            ?? $progress['period_key']
            ?? ($periodKey !== '' ? $periodKey : null);

        if ($raw === null || $raw === '') {
            $tz = (string) ($request->office->timezone ?? 'America/Sao_Paulo') ?: 'America/Sao_Paulo';
            $raw = (string) PgmeiYear::yearForDailyCycle(null, $tz);
        }

        $digits = preg_replace('/\D/', '', (string) $raw) ?? '';
        $year = substr($digits, 0, 4);

        return $this->pgmeiCodec24->buildPayload($year);
    }
}

<?php

namespace App\Services\Integra\Parcelamento;

use App\Contracts\FiscalSourceAdapter;
use App\Contracts\ParcelamentoSource;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\TaxInstallmentModality;
use App\Services\Integra\TaxProxyPowerService;
use App\Support\FeatureFlags;

/**
 * Adapter somente-leitura para modalidades catalogadas de parcelamento SN/MEI.
 * service_code = modalidade (PARCSN, PARCMEI, …).
 */
final class ParcelamentoReadAdapter implements FiscalSourceAdapter
{
    public function __construct(
        private readonly ParcelamentoSource $source,
        private readonly ParcelamentoProjectionService $projection,
        private readonly TaxProxyPowerService $proxyPowers,
        private readonly ParcelamentoOfficialCodec $codec,
    ) {}

    public function systemCode(): string
    {
        return ParcelamentoServiceCatalog::SOLUTION;
    }

    public function serviceCode(): string
    {
        // Wildcard — supports() filtra modalidades
        return '*';
    }

    public function operationCode(): string
    {
        return '*';
    }

    public function mutability(): FiscalMutability
    {
        return FiscalMutability::ReadOnly;
    }

    public function coverage(): FiscalCoverage
    {
        return FiscalCoverage::Full;
    }

    public function moduleKey(): ?string
    {
        return ParcelamentoServiceCatalog::MODULE_KEY;
    }

    public function supports(FiscalAdapterRequest $request): bool
    {
        if (strcasecmp($request->systemCode, ParcelamentoServiceCatalog::SOLUTION) !== 0) {
            return false;
        }

        $modality = ParcelamentoServiceCatalog::parseModality($request->serviceCode);
        if ($modality === null) {
            return false;
        }

        $op = strtoupper($request->operationCode);

        return ParcelamentoServiceCatalog::isReadOperation($op);
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        $modality = ParcelamentoServiceCatalog::parseModality($request->serviceCode);
        if ($modality === null) {
            return FiscalAdapterResult::unsupported(
                "Modalidade de parcelamento desconhecida: {$request->serviceCode}"
            );
        }

        if (! FeatureFlags::isModuleEnabled(ParcelamentoServiceCatalog::MODULE_KEY, $request->office->id)
            && ! (bool) config('fiscal_monitoring.enabled', false)) {
            return FiscalAdapterResult::blocked(
                'Módulo parcelamentos desabilitado.',
                'FEATURE_DISABLED',
            );
        }

        $powerBlock = $this->assertPower($request, $modality);
        if ($powerBlock !== null) {
            return $powerBlock;
        }

        $operation = strtoupper($request->operationCode);
        $payload = is_array($request->context['payload'] ?? null)
            ? $request->context['payload']
            : [];

        // MONITOR consolida pedidos + parcelas + amostra de pagamento
        if ($operation === 'MONITOR') {
            return $this->executeMonitor($request, $modality, $payload);
        }

        $response = $this->source->execute($modality, $operation, $payload, $request);
        if (! $response['success']) {
            return FiscalAdapterResult::failed(
                $response['error_message'] ?? 'Falha na consulta de parcelamento',
                $response['error_code'] ?? 'PARCELAMENTO_SOURCE_FAILED',
                FiscalCoverage::Full,
            );
        }

        $body = $response['body'];
        $isTrial = (bool) ($response['simulated'] ?? false);
        $evidence = json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $sha = hash('sha256', $evidence);

        $projected = $this->projection->projectFromMonitorBody(
            $request->office,
            $request->client,
            $modality,
            $this->enrichBodyForProjection($modality, $operation, $body, $payload),
            $request->run,
            $sha,
        );

        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: $projected['situation'],
            coverage: FiscalCoverage::Full,
            evidenceBytes: $evidence,
            evidenceContentType: 'application/json',
            sourceVersion: $isTrial ? 'parcelamento-serpro-trial' : 'parcelamento-serpro',
            normalized: [
                'modality' => $modality->value,
                'regime' => $modality->regime(),
                'operation' => $operation,
                'orders' => array_map(
                    fn ($o) => $o->toPublicArray(),
                    $projected['orders'],
                ),
                'parcel_count' => count($projected['parcels']),
                'payment_count' => count($projected['payments']),
                'simulated' => $isTrial,
            ],
            findings: $projected['findings'],
            itemsProcessed: count($projected['orders']) + count($projected['parcels']),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function executeMonitor(
        FiscalAdapterRequest $request,
        TaxInstallmentModality $modality,
        array $payload,
    ): FiscalAdapterResult {
        $pedidosResp = $this->source->execute($modality, 'CONSULTAR_PEDIDOS', $payload, $request);
        if (! $pedidosResp['success']) {
            return FiscalAdapterResult::failed(
                $pedidosResp['error_message'] ?? 'Falha ao listar pedidos',
                $pedidosResp['error_code'] ?? 'PARCELAMENTO_PEDIDOS_FAILED',
            );
        }

        $pedidos = $this->codec->orders($pedidosResp['body']);
        $isTrial = (bool) ($pedidosResp['simulated'] ?? false);
        $detalhes = [];
        $partialFindings = [];
        $maxOrders = max(1, (int) config('fiscal_monitoring.installments.max_orders_per_run', 25));

        foreach (array_slice($pedidos, 0, $maxOrders) as $pedido) {
            $numero = (string) ($pedido['numero'] ?? '');
            if ($numero === '') {
                continue;
            }

            $det = $this->source->execute($modality, 'CONSULTAR_PARCELAMENTO', [
                'numeroParcelamento' => $numero,
            ], $request);
            if ($det['success']) {
                $detalhes[$numero] = $det['body'];
            } else {
                $partialFindings[] = $this->partialFailureFinding(
                    'PARCELAMENTO_DETALHE_PARCIAL',
                    "Pedido {$numero} sem detalhe nesta execução.",
                    $det['error_code'] ?? null,
                );
            }
        }

        if (count($pedidos) > $maxOrders) {
            $partialFindings[] = $this->partialFailureFinding(
                'PARCELAMENTO_LIMITE_PEDIDOS',
                'A execução processou os '.$maxOrders.' pedidos mais recentes do limite configurado.',
                null,
            );
        }

        // Contrato oficial: PARCELASPARAGERAR não recebe numeroParcelamento.
        $paraGerar = $this->source->execute($modality, 'CONSULTAR_PARCELAS', [], $request);
        $availableBody = $paraGerar['success'] ? $paraGerar['body'] : [];
        if (! $paraGerar['success']) {
            $partialFindings[] = $this->partialFailureFinding(
                'PARCELAMENTO_PARCELAS_PARCIAL',
                'Parcelas disponíveis para emissão não foram atualizadas nesta execução.',
                $paraGerar['error_code'] ?? null,
            );
        }

        $consolidated = array_merge(
            $this->codec->normalizeMonitor($pedidosResp['body'], $detalhes, $availableBody),
            [
                'modality' => $modality->value,
                'regime' => $modality->regime(),
            ],
        );

        $evidence = json_encode($consolidated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $sha = hash('sha256', $evidence);

        $projected = $this->projection->projectFromMonitorBody(
            $request->office,
            $request->client,
            $modality,
            $consolidated,
            $request->run,
            $sha,
        );

        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: $projected['situation'],
            coverage: FiscalCoverage::Full,
            evidenceBytes: $evidence,
            evidenceContentType: 'application/json',
            sourceVersion: $isTrial ? 'parcelamento-serpro-trial' : 'parcelamento-serpro',
            normalized: [
                'modality' => $modality->value,
                'regime' => $modality->regime(),
                'operation' => 'MONITOR',
                'orders' => array_map(fn ($o) => $o->toPublicArray(), $projected['orders']),
                'parcel_count' => count($projected['parcels']),
                'payment_count' => count($projected['payments']),
                'simulated' => $isTrial,
            ],
            findings: [...$projected['findings'], ...$partialFindings],
            itemsProcessed: count($projected['orders']) + count($projected['parcels']),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function enrichBodyForProjection(
        TaxInstallmentModality $modality,
        string $operation,
        array $body,
        array $payload,
    ): array {
        if ($operation === 'CONSULTAR_PAGAMENTO') {
            $key = (string) ($payload['anoMesParcela'] ?? $body['paDasGerado'] ?? $body['numeroParcela'] ?? '');
            $orderId = (string) ($payload['numeroParcelamento'] ?? $body['numeroParcelamento'] ?? '');
            $payment = $this->codec->paymentDetail($body, $orderId, $key);

            return [
                'pedidos' => [[
                    'numero' => $orderId !== '' ? $orderId : 'UNKNOWN',
                    'situacao' => 'EM_ANDAMENTO',
                    'parcelas' => [[
                        'parcela' => $key,
                        'vencimento' => $body['dataVencimento'] ?? null,
                        'valorCentavos' => $payment['valorPagoCentavos'] ?? null,
                        'situacaoFonte' => ! empty($payment['pagamentoConfirmado']) ? 'PAGA' : 'EM_ABERTO',
                    ]],
                    'pagamentos' => $key !== '' ? [$key => $payment] : [],
                ]],
            ];
        }

        if ($operation === 'CONSULTAR_PARCELAS') {
            return [
                'pedidos' => [],
                'unassigned_available_parcels' => $this->codec->availableParcels($body),
            ];
        }

        if ($operation === 'CONSULTAR_PARCELAMENTO') {
            $orderId = (string) ($body['numero'] ?? $body['numeroParcelamento'] ?? $payload['numeroParcelamento'] ?? 'UNKNOWN');

            return $this->codec->normalizeMonitor([], [$orderId => $body], []);
        }

        if ($operation === 'CONSULTAR_PEDIDOS') {
            return $this->codec->normalizeMonitor($body, [], []);
        }

        return $body;
    }

    /** @return array<string, mixed> */
    private function partialFailureFinding(string $code, string $detail, ?string $sourceCode): array
    {
        return [
            'code' => $code,
            'severity' => FiscalFindingSeverity::Info->value,
            'title' => 'Monitoramento parcial de parcelamento',
            'detail' => $detail.($sourceCode !== null ? " Código: {$sourceCode}." : ''),
            'situation' => FiscalSituation::Unknown->value,
            'creates_pending' => false,
        ];
    }

    private function assertPower(
        FiscalAdapterRequest $request,
        TaxInstallmentModality $modality,
    ): ?FiscalAdapterResult {
        $author = (string) ($request->context['author_identity'] ?? '');
        if ($author === '') {
            // Sem autor no contexto: adapters de leitura ainda projetam em trial com fake,
            // mas marcamos finding de poder não verificado apenas se explicitamente exigido.
            if (! empty($request->context['require_proxy_power'])) {
                return FiscalAdapterResult::blocked(
                    "Procuração/poder {$modality->requiredPowerCode()} não verificado (autor ausente).",
                    'PROXY_POWER_MISSING',
                );
            }

            return null;
        }

        $power = $this->proxyPowers->findUsablePower(
            (int) $request->office->id,
            (int) $request->client->id,
            $modality->requiredPowerCode(),
            $author,
        );

        if ($power === null) {
            return new FiscalAdapterResult(
                result: FiscalRunResult::Blocked,
                situation: FiscalSituation::Blocked,
                coverage: FiscalCoverage::Full,
                findings: [[
                    'code' => 'PROXY_POWER_MISSING',
                    'severity' => FiscalFindingSeverity::High->value,
                    'title' => 'Poder de procuração ausente para modalidade',
                    'detail' => "Modalidade {$modality->value} exige poder {$modality->requiredPowerCode()}.",
                    'situation' => FiscalSituation::Blocked->value,
                    'creates_pending' => false,
                ]],
                skipReason: 'PROXY_POWER_MISSING',
                errorCode: 'PROXY_POWER_MISSING',
                errorMessage: "Sem poder {$modality->requiredPowerCode()} para {$modality->value}.",
            );
        }

        return null;
    }
}

<?php

namespace App\Services\Fiscal\Guides;

use App\Contracts\GuideEmissionClient;
use App\DTO\Serpro\IntegraResponse;
use App\DTO\Serpro\MutationAuthorization;
use App\Enums\SerproCapabilityDriver;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Services\Fiscal\Guides\DTO\GuideEmissionRequest;
use App\Services\Fiscal\Guides\DTO\GuideEmissionResult;
use App\Services\Fiscal\Guides\DTO\GuidePaymentLookupRequest;
use App\Services\Fiscal\Guides\DTO\GuidePaymentLookupResult;
use App\Services\Fiscal\Guides\DTO\GuideReconcileRequest;
use App\Services\Fiscal\Guides\DTO\GuideReconcileResult;
use App\Services\Fiscal\Guides\Exceptions\GuideTransportTimeoutException;
use App\Services\Integra\ContributorCnpjResolver;
use App\Services\Serpro\CapabilityDriverResolver;
use App\Services\Serpro\Catalog\OperationKeyMap;
use App\Services\Serpro\SerproContractService;
use App\Services\Serpro\SerproOperationService;
use RuntimeException;

/**
 * Adapter real da central de guias para SICALC/PAGTOWEB.
 *
 * O adapter nunca aceita coordenadas HTTP vindas do consumidor: converte apenas
 * códigos de domínio conhecidos em operation_key e deixa rota/serviço/versão
 * para o catálogo oficial. Emissão ambígua não é repetida na reconciliação.
 */
final class SerproGuideEmissionClient implements GuideEmissionClient
{
    private const EMISSION_OPERATION = 'sicalc.consolidargerardarf';

    private const PAYMENT_OPERATION = 'pagtoweb.pagamentos';

    public function __construct(
        private readonly SerproContractService $contracts,
        private readonly CapabilityDriverResolver $drivers,
        private readonly ContributorCnpjResolver $contributors,
        private readonly SerproOperationService $operations,
    ) {}

    public function emit(GuideEmissionRequest $request): GuideEmissionResult
    {
        [$office, $client] = $this->context(
            $request->officeId,
            $request->clientId,
        );

        $operationKey = OperationKeyMap::require(
            null,
            $request->systemCode,
            $request->serviceCode,
            $request->operationCode,
        );
        if ($operationKey !== self::EMISSION_OPERATION) {
            throw new RuntimeException('Operação de emissão não suportada pelo adapter SICALC.');
        }

        $businessData = $this->emissionData($request);
        // Mutante: executor aplica MutationAuthorization tipada (bloqueado nesta change).
        $response = $this->operations->execute(
            office: $office,
            client: $client,
            operationKey: $operationKey,
            businessData: $businessData,
            idempotencyKey: $request->idempotencyKey,
            correlationId: $request->correlationId,
            mutationAuth: MutationAuthorization::none(),
            module: 'guias',
        );

        if ($response->errorCode === 'MUTATION_TIMEOUT_PENDING' || $response->httpStatus === 0) {
            throw new GuideTransportTimeoutException(
                'Timeout ambíguo após envio ao SICALC; emissão exige conciliação manual.',
                $request->correlationId,
            );
        }
        $this->assertSuccess($response, 'emissão SICALC');

        $dados = $this->arrayData($response->dados);
        $pdfBase64 = $this->firstString($dados, ['pdf', 'arquivo', 'documentoPdf', 'darfPdf', 'conteudo']);
        $pdf = $pdfBase64 !== null ? base64_decode($pdfBase64, true) : false;
        if (! is_string($pdf) || ! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('SICALC retornou documento PDF ausente ou inválido.');
        }

        $identifier = $this->firstString($dados, ['numeroDocumento', 'numeroDarf', 'codigo44', 'identificador']);
        if ($identifier === null) {
            throw new RuntimeException('SICALC retornou documento sem identificador oficial.');
        }

        $amount = $this->moneyToCents($this->firstScalar($dados, ['valorTotalConsolidado', 'valorTotal']))
            ?? $request->amountCents;
        if ($amount === null) {
            throw new RuntimeException('SICALC retornou documento sem valor consolidado.');
        }

        return new GuideEmissionResult(
            documentBytes: $pdf,
            contentType: 'application/pdf',
            identifierCode: $identifier,
            amountCents: $amount,
            dueAtIso: $this->firstString($dados, ['vencimento', 'dataVencimento']) ?? $request->dueAtIso,
            validUntilIso: $this->firstString($dados, ['dataValidadeCalculo', 'validade']),
            remoteProtocol: $this->firstString($dados, ['protocolo', 'numeroProtocolo']),
            correlationId: $response->correlationId ?? $request->correlationId,
            latencyMs: (int) ($response->latencyMs ?? 0),
            simulated: false,
        );
    }

    public function reconcile(GuideReconcileRequest $request): GuideReconcileResult
    {
        // O catálogo não oferece consulta idempotente de uma emissão SICALC por
        // request tag. Repetir o POST poderia gerar segundo DARF.
        return new GuideReconcileResult(
            outcome: 'STILL_UNKNOWN',
            errorCode: 'MANUAL_RECONCILIATION_REQUIRED',
            errorMessage: 'SICALC não expõe conciliação idempotente da emissão por request tag.',
        );
    }

    public function lookupPayment(GuidePaymentLookupRequest $request): GuidePaymentLookupResult
    {
        [$office, $client] = $this->context(
            $request->officeId,
            $request->clientId,
        );

        $businessData = [
            'tamanhoDaPagina' => 100,
            'primeiroDaPagina' => 0,
        ];
        if ($request->identifierCode !== null && $request->identifierCode !== '') {
            $businessData['numeroDocumento'] = mb_substr($request->identifierCode, 0, 17);
        }

        $response = $this->operations->execute(
            $office,
            $client,
            self::PAYMENT_OPERATION,
            $businessData,
            correlationId: $request->correlationId,
        );
        $this->assertSuccess($response, 'consulta PAGTOWEB');

        $dados = $this->arrayData($response->dados);
        $rows = $this->listData($dados, ['pagamentos', 'documentos', 'items', 'lista']);
        $match = null;
        foreach ($rows as $row) {
            $number = $this->firstString($row, ['numeroDocumento', 'identificador']);
            if ($request->identifierCode === null || $number === $request->identifierCode) {
                $match = $row;
                break;
            }
        }
        if ($match === null) {
            return new GuidePaymentLookupResult(status: 'NOT_FOUND', official: true);
        }

        $externalId = $this->firstString($match, ['numeroDocumento', 'identificador']);
        if ($externalId === null) {
            throw new RuntimeException('PAGTOWEB retornou pagamento sem identificador externo.');
        }

        return new GuidePaymentLookupResult(
            status: 'PAID',
            externalId: $externalId,
            amountCents: $this->moneyToCents($this->firstScalar($match, ['valorTotal', 'valorPago'])),
            paidAtIso: $this->firstString($match, ['dataArrecadacao', 'dataPagamento']),
            source: 'PAGTOWEB',
            evidenceBytes: json_encode($match, JSON_THROW_ON_ERROR),
            contentType: 'application/json',
            official: true,
        );
    }

    /** @return array{Office,Client} */
    private function context(int $officeId, int $clientId): array
    {
        if ($this->drivers->forCapability('guides') !== SerproCapabilityDriver::Real) {
            throw new RuntimeException('Adapter real de guias está desabilitado.');
        }

        $office = Office::query()->withoutGlobalScopes()->findOrFail($officeId);
        $client = Client::query()->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereKey($clientId)
            ->firstOrFail();
        $environment = SerproEnvironment::tryFrom(strtoupper((string) config('serpro.default_environment', 'TRIAL')))
            ?? SerproEnvironment::Trial;
        $contract = $this->contracts->activeFor($environment);
        if ($contract === null || ! $contract->isUsable()) {
            throw new RuntimeException('Contrato SERPRO indisponível para guias.');
        }
        $authorization = OfficeSerproAuthorization::query()
            ->where('office_id', $officeId)
            ->where('environment', $environment->value)
            ->first();
        $author = strtoupper(trim((string) ($authorization?->author_identity ?? '')));
        if ($author === '') {
            throw new RuntimeException('Autor do Pedido não configurado para guias.');
        }
        $this->contributors->resolve($client);

        return [$office, $client];
    }

    /** @return array<string, mixed> */
    private function emissionData(GuideEmissionRequest $request): array
    {
        $data = $request->payload;
        $allowed = [
            'uf', 'municipio', 'codigoReceita', 'codigoReceitaExtensao',
            'numeroReferencia', 'tipoPA', 'dataPA', 'vencimento', 'cota',
            'valorImposto', 'valorMulta', 'valorJuros', 'ganhoCapital',
            'dataAlienacao', 'dataConsolidacao', 'observacao', 'cno', 'cnpjPrestador',
        ];
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown !== []) {
            throw new RuntimeException('Campos não documentados no pedido SICALC: '.implode(', ', $unknown).'.');
        }
        $data['numeroReferencia'] ??= $request->debitRef;
        $data['dataPA'] ??= $request->competencePeriodKey;
        $data['vencimento'] ??= $request->dueAtIso;
        $data['valorImposto'] ??= $request->amountCents !== null ? $request->amountCents / 100 : null;
        $data['dataConsolidacao'] ??= CarbonImmutable::now()->toDateString();
        $data = array_filter($data, static fn (mixed $value): bool => $value !== null && $value !== '');

        foreach (['codigoReceita', 'codigoReceitaExtensao', 'dataPA', 'valorImposto', 'dataConsolidacao'] as $field) {
            if (! array_key_exists($field, $data)) {
                throw new RuntimeException("Campo oficial obrigatório ausente para SICALC: {$field}.");
            }
        }

        return $data;
    }

    private function assertSuccess(IntegraResponse $response, string $action): void
    {
        if (! $response->success) {
            $code = $response->errorCode ?? 'SERPRO_REJECTED';
            throw new RuntimeException("Falha na {$action}: {$code}.");
        }
    }

    /** @return array<string, mixed> */
    private function arrayData(mixed $dados): array
    {
        if (is_string($dados)) {
            $decoded = json_decode($dados, true);
            $dados = is_array($decoded) ? $decoded : [];
        }

        return is_array($dados) ? $dados : [];
    }

    /** @return list<array<string, mixed>> */
    private function listData(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return array_values(array_filter($data[$key], 'is_array'));
            }
        }
        if (array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }

        return [];
    }

    private function firstString(array $data, array $keys): ?string
    {
        $value = $this->firstScalar($data, $keys);

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private function firstScalar(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && is_scalar($data[$key])) {
                return $data[$key];
            }
        }
        foreach ($data as $value) {
            if (is_array($value)) {
                $found = $this->firstScalar($value, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    private function moneyToCents(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value * 100);
    }
}

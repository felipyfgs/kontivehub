<?php

namespace App\Services\Integra\Dctfweb;

use App\Contracts\SerproOperationExecutor;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Integra\MitListaApuracoesRequest;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\SerproEnvironment;
use App\Services\Serpro\Catalog\OperationKeyMap;

/**
 * Monta chamada DCTF/MIT via executor central.
 * Nunca aceita CNPJ/autor vindos do frontend como autoridade.
 * Ledger, gates e idempotência ficam no SerproOperationService.
 */
final class DctfwebIntegraCaller
{
    public function __construct(
        private readonly SerproOperationExecutor $operations,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function call(
        FiscalAdapterRequest $request,
        string $solutionCode,
        string $serviceCode,
        string $operationCode,
        array $payload = [],
        ?string $idempotencyKey = null,
    ): IntegraResponse {
        $operationKey = OperationKeyMap::require(
            null,
            $solutionCode,
            $serviceCode,
            $operationCode,
        );

        $correlationId = $request->run->correlation_id;
        $idem = $idempotencyKey ?? $request->run->idempotency_key;
        $idem = 'dctf:'.$idem.':'.$operationCode;

        return $this->operations->execute(
            office: $request->office,
            client: $request->client,
            operationKey: $operationKey,
            businessData: $payload,
            idempotencyKey: $idem,
            correlationId: $correlationId,
            entityKey: 'client:'.(string) $request->client->id,
            module: DctfwebCodes::MODULE,
        );
    }

    public function resolveEnvironment(): SerproEnvironment
    {
        $raw = strtoupper((string) config('serpro.default_environment', 'TRIAL'));

        return SerproEnvironment::tryFrom($raw) ?? SerproEnvironment::Trial;
    }

    /**
     * Chamada tipada da consulta oficial MIT/LISTAAPURACOES317.
     * Identidades e coordenadas continuam inteiramente no executor central.
     */
    public function callMitListaApuracoes(
        FiscalAdapterRequest $request,
        MitListaApuracoesRequest $filters,
    ): IntegraResponse {
        return $this->call(
            request: $request,
            solutionCode: DctfwebCodes::SYSTEM_MIT,
            serviceCode: DctfwebCodes::SERVICE_MIT,
            operationCode: DctfwebCodes::OP_MIT_LISTAR_APURACOES,
            payload: $filters->toPayload(),
        );
    }

    /**
     * Serializa body para evidência (bytes estáveis).
     *
     * @param  array<string, mixed>  $body
     */
    public static function evidenceBytes(array $body): string
    {
        return json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}

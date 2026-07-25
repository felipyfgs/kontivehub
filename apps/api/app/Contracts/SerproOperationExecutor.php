<?php

namespace App\Contracts;

use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;
use App\DTO\Serpro\MutationAuthorization;
use App\DTO\Serpro\SerproOperationCommand;
use App\Models\Client;
use App\Models\Office;

/**
 * Único entrypoint produtivo para operações Integra Contador.
 *
 * Adapters, jobs e controllers de negócio MUST NOT injetar ou invocar
 * IntegraContadorClient / HttpIntegraContadorClient diretamente.
 */
interface SerproOperationExecutor
{
    /**
     * Executa via comando tipado (preferido).
     */
    public function run(SerproOperationCommand $command): IntegraResponse;

    /**
     * Atalho de compatibilidade para callers com Office/Client.
     *
     * @param  array<string, mixed>  $businessData
     */
    public function execute(
        Office $office,
        Client $client,
        string $operationKey,
        array $businessData = [],
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
        ?MutationAuthorization $mutationAuth = null,
        ?string $entityKey = null,
        ?string $module = null,
    ): IntegraResponse;

    /**
     * Entrada para adapters que já montaram o {@see IntegraRequest} (ex.: Autentica Procurador).
     */
    public function executeRequest(
        IntegraRequest $request,
        ?MutationAuthorization $mutationAuth = null,
        ?string $module = null,
    ): IntegraResponse;
}

<?php

namespace App\Services\Fiscal\Mutations;

use App\Contracts\SerproFiscalMutationTransport;
use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;
use App\DTO\Serpro\MutationAuthorization;
use App\Models\FiscalMutationOperation;
use App\Services\Serpro\Catalog\OperationKeyMap;
use App\Services\Serpro\SerproOperationService;

/**
 * Transporte mutante via executor central, autorizado exclusivamente por uma
 * operação persistida e revalidada pelo FiscalMutationService.
 */
final class IntegraFiscalMutationTransport implements SerproFiscalMutationTransport
{
    public function __construct(
        private readonly SerproOperationService $operations,
    ) {}

    public function execute(IntegraRequest $request): IntegraResponse
    {
        $operation = $this->persistedOperation($request);

        return $this->operations->executeRequest(
            $request,
            mutationAuth: $operation === null
                ? MutationAuthorization::none()
                : MutationAuthorization::fromPersistedOperation($operation, $request->operationKey),
            module: $operation?->module_key,
        );
    }

    public function reconcile(IntegraRequest $request): IntegraResponse
    {
        // Consulta de reconciliação — nunca reenvia a mutação original.
        $reconcileOp = $this->reconcileOperationCode((string) ($request->operationCode ?? ''));
        $reconcileKey = OperationKeyMap::resolve(
            null,
            $request->solutionCode,
            $request->serviceCode,
            $reconcileOp,
        ) ?? $request->operationKey;

        $query = new IntegraRequest(
            officeId: $request->officeId,
            clientId: $request->clientId,
            environment: $request->environment,
            contractorCnpj: $request->contractorCnpj,
            authorIdentity: $request->authorIdentity,
            contributorCnpj: $request->contributorCnpj,
            operationKey: $reconcileKey,
            payload: array_merge($request->payload, [
                'reconcile' => true,
                'original_operation' => $request->operationCode,
            ]),
            headers: $request->headers,
            idempotencyKey: ($request->idempotencyKey ?? '').':reconcile',
            correlationId: $request->correlationId,
            isMutating: false,
            solutionCode: $request->solutionCode,
            serviceCode: $request->serviceCode,
            operationCode: $reconcileOp,
        );

        return $this->operations->executeRequest($query, mutationAuth: MutationAuthorization::none());
    }

    private function reconcileOperationCode(string $operationCode): string
    {
        $map = [
            'TRANSMITIR' => 'CONSULTAR_RECIBO',
            'TRANSMITIR_DECLARACAO' => 'CONSULTAR_RECIBO',
            'EMITIR_GUIA' => 'CONSULTAR',
            'ENCERRAR' => 'CONSULTAR_SITUACAO',
            'ADERIR' => 'CONSULTAR_PEDIDO',
        ];

        $upper = strtoupper($operationCode);

        return $map[$upper] ?? 'CONSULTAR_RECIBO';
    }

    private function persistedOperation(IntegraRequest $request): ?FiscalMutationOperation
    {
        $id = $request->payload['mutation_operation_id'] ?? null;
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        $operation = FiscalMutationOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $request->officeId)
            ->where('client_id', $request->clientId)
            ->whereKey((int) $id)
            ->first();
        if ($operation === null) {
            return null;
        }

        $digest = FiscalMutationPayload::digest($request->businessData);
        if (! is_string($operation->request_payload_digest)
            || ! hash_equals($operation->request_payload_digest, $digest)
        ) {
            return null;
        }

        return $operation;
    }
}

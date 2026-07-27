<?php

namespace App\Services\Fiscal\Mutations;

use App\Contracts\SerproFiscalMutationTransport;
use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;
use App\DTO\Serpro\MutationAuthorization;
use App\Models\FiscalMutationOperation;
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
        $reconcileKey = $this->reconcileOperationKey($request->operationKey);

        $query = new IntegraRequest(
            tenantId: $request->tenantId,
            clientId: $request->clientId,
            environment: $request->environment,
            contractorCnpj: $request->contractorCnpj,
            authorIdentity: $request->authorIdentity,
            contributorCnpj: $request->contributorCnpj,
            operationKey: $reconcileKey,
            businessData: [
                'reconcile' => true,
                'original_operation_key' => $request->operationKey,
            ],
            headers: $request->headers,
            idempotencyKey: ($request->idempotencyKey ?? '').':reconcile',
            correlationId: $request->correlationId,
            isMutating: false,
        );

        return $this->operations->executeRequest($query, mutationAuth: MutationAuthorization::none());
    }

    private function reconcileOperationKey(string $operationKey): string
    {
        return match (strtolower($operationKey)) {
            'pgdasd.transdeclaracao' => 'pgdasd.consultimadecrec',
            'defis.transdeclaracao' => 'defis.consultimadecrec',
            'dctfweb.transdeclaracao' => 'dctfweb.consrecibo',
            'mit.encapuracao' => 'mit.situacaoenc',
            default => $operationKey,
        };
    }

    private function persistedOperation(IntegraRequest $request): ?FiscalMutationOperation
    {
        $id = $request->mutationOperationId;
        if ($id === null) {
            return null;
        }

        $operation = FiscalMutationOperation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $request->tenantId)
            ->where('client_id', $request->clientId)
            ->whereKey($id)
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

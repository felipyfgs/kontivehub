<?php

namespace App\Actions\Fiscal\Mutations;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\DTO\Fiscal\Mutations\DeclarationOperationExecuteData;
use App\DTO\Fiscal\Mutations\DeclarationOperationPreflightData;
use App\DTO\Fiscal\Mutations\DeclarationOperationReadData;
use App\Enums\TenantPermission;
use App\Models\Client;
use App\Models\FiscalMutationOperation;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\Declarations\DeclarationMutationService;
use App\Services\Fiscal\Declarations\DeclarationOperationPresenter;
use App\Services\Fiscal\Declarations\DeclarationOperationReadService;
use App\Services\Fiscal\Declarations\DeclarationOperationRegistry;
use App\Services\Fiscal\Mutations\FiscalMutationService;
use App\Services\Fiscal\Mutations\MutationPreflightResult;
use App\Support\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class OperateDeclarationOperationAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantAuthorization $authorization,
        private FindFiscalClientAction $findClient,
        private DeclarationOperationRegistry $registry,
        private DeclarationOperationReadService $reads,
        private DeclarationMutationService $declarationMutations,
        private FiscalMutationService $mutations,
        private DeclarationOperationPresenter $presenter,
    ) {}

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function read(
        User $actor,
        string $action,
        DeclarationOperationReadData $data,
    ): array {
        $client = $this->requireClient($data->clientId);
        $this->assertPermission($actor, TenantPermission::FiscalSyncTrigger, $client);

        $payload = $this->reads->execute(
            tenant: $this->currentTenant->tenant(),
            client: $client,
            actionId: $action,
            params: $data->params,
            confirmed: $data->confirmed,
            actorUserId: $actor->id,
        );

        return [
            'payload' => $this->presenter->readPayload($payload, $action),
            'status' => ($payload['async'] ?? false) ? 202 : 201,
        ];
    }

    public function preflight(
        User $actor,
        string $action,
        DeclarationOperationPreflightData $data,
    ): MutationPreflightResult {
        $client = $this->requireClient($data->clientId);
        $this->assertPermission($actor, TenantPermission::FiscalMutationsExecute, $client);

        return $this->declarationMutations->preflight(
            $this->currentTenant->tenant(),
            $client,
            $actor,
            $action,
            $data->params,
            $data->idempotencyKey,
        );
    }

    public function execute(
        User $actor,
        string $action,
        DeclarationOperationExecuteData $data,
    ): FiscalMutationOperation {
        $client = $this->requireClient($data->clientId);
        $this->assertPermission($actor, TenantPermission::FiscalMutationsExecute, $client);

        return $this->declarationMutations->execute(
            $this->currentTenant->tenant(),
            $client,
            $actor,
            $action,
            $data->params,
            $data->idempotencyKey,
            $data->preflightToken,
            $data->confirmationPhrase,
            $data->confirmed,
        );
    }

    /**
     * @return array{operation: FiscalMutationOperation, action: string}
     */
    public function show(int $mutationId): array
    {
        $operation = $this->mutations->findForTenant(
            $this->currentTenant->tenant(),
            $mutationId,
        );
        if ($operation === null) {
            throw new NotFoundHttpException('Operação não encontrada.');
        }

        try {
            $action = $this->registry->actionIdFor($operation->operation_key);
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Operação não encontrada.');
        }

        return ['operation' => $operation, 'action' => $action];
    }

    /**
     * @return array{operation: FiscalMutationOperation, action: string}
     */
    public function reconcile(User $actor, int $mutationId): array
    {
        $this->assertPermission($actor, TenantPermission::FiscalMutationsExecute);
        $operation = $this->mutations->findForTenant(
            $this->currentTenant->tenant(),
            $mutationId,
        );
        if ($operation === null) {
            throw new NotFoundHttpException('Operação não encontrada.');
        }

        try {
            $action = $this->registry->actionIdFor($operation->operation_key);
            $result = $this->mutations->reconcile(
                $this->currentTenant->tenant(),
                $operation,
                $actor,
            );
        } catch (\InvalidArgumentException) {
            throw new NotFoundHttpException('Operação não encontrada.');
        }

        return ['operation' => $result, 'action' => $action];
    }

    public function presentMutation(
        FiscalMutationOperation $operation,
        string $action,
    ): array {
        return $this->presenter->mutation($operation, $action);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function presentPreflight(array $payload, string $action): array
    {
        return $this->presenter->preflight($payload, $action);
    }

    private function requireClient(int $clientId): Client
    {
        $client = $this->findClient->handle(
            $this->currentTenant->tenant(),
            $clientId,
        );
        if ($client === null) {
            throw new NotFoundHttpException(
                'Cliente não encontrado no escritório atual.',
            );
        }

        return $client;
    }

    private function assertPermission(
        User $actor,
        TenantPermission $permission,
        ?Client $client = null,
    ): void {
        if (! $this->authorization->allows($actor, $permission, $client)) {
            throw new AuthorizationException(
                'Sem permissão para esta operação fiscal.',
            );
        }
    }
}

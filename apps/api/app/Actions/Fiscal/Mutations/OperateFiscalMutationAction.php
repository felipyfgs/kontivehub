<?php

namespace App\Actions\Fiscal\Mutations;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\DTO\Fiscal\Mutations\FiscalMutationExecuteData;
use App\DTO\Fiscal\Mutations\FiscalMutationPreflightData;
use App\Models\Client;
use App\Models\FiscalMutationOperation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Fiscal\Mutations\FiscalMutationService;
use App\Services\Fiscal\Mutations\MutationPreflightResult;
use App\Support\CurrentTenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class OperateFiscalMutationAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private FiscalMutationService $mutations,
        private FindFiscalClientAction $findClient,
    ) {}

    public function preflight(
        User $actor,
        FiscalMutationPreflightData $data,
    ): MutationPreflightResult {
        $tenant = $this->currentTenant->tenant();
        $client = $this->requireClient($tenant, $data->clientId);

        return $this->mutations->preflight(
            tenant: $tenant,
            client: $client,
            user: $actor,
            solutionCode: $data->solutionCode,
            serviceCode: $data->serviceCode,
            operationCode: $data->operationCode,
            operationKey: $data->operationKey,
            competencePeriodKey: $data->competencePeriodKey,
            idempotencyKey: $data->idempotencyKey,
            environment: $data->environment,
            requestPayload: $data->payload,
            module: $data->module,
        );
    }

    public function execute(
        User $actor,
        FiscalMutationExecuteData $data,
    ): FiscalMutationOperation {
        $tenant = $this->currentTenant->tenant();
        $client = $this->requireClient($tenant, $data->clientId);

        return $this->mutations->execute(
            tenant: $tenant,
            client: $client,
            user: $actor,
            solutionCode: $data->solutionCode,
            serviceCode: $data->serviceCode,
            operationCode: $data->operationCode,
            operationKey: $data->operationKey,
            confirmationPhrase: $data->confirmationPhrase,
            confirmed: $data->confirmed,
            competencePeriodKey: $data->competencePeriodKey,
            idempotencyKey: $data->idempotencyKey,
            preflightToken: $data->preflightToken,
            environment: $data->environment,
            requestPayload: $data->payload,
            module: $data->module,
        );
    }

    public function show(int $mutationId): FiscalMutationOperation
    {
        $tenant = $this->currentTenant->tenant();
        $model = $this->mutations->findForTenant($tenant, $mutationId);
        if ($model === null) {
            throw new NotFoundHttpException('Operação não encontrada.');
        }

        return $model;
    }

    public function reconcile(
        User $actor,
        int $mutationId,
    ): FiscalMutationOperation {
        $tenant = $this->currentTenant->tenant();
        $model = $this->mutations->findForTenant($tenant, $mutationId);
        if ($model === null) {
            throw new NotFoundHttpException('Operação não encontrada.');
        }

        return $this->mutations->reconcile($tenant, $model, $actor);
    }

    private function requireClient(Tenant $tenant, int $clientId): Client
    {
        $client = $this->findClient->handle($tenant, $clientId);
        if ($client === null) {
            throw new NotFoundHttpException('Cliente não encontrado.');
        }

        return $client;
    }
}

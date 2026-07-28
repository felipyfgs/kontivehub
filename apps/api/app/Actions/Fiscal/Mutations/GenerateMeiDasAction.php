<?php

namespace App\Actions\Fiscal\Mutations;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\DTO\Fiscal\Mutations\GenerateMeiDasData;
use App\Enums\FiscalMutationStatus;
use App\Models\Client;
use App\Models\FiscalMutationOperation;
use App\Models\MeiAutomationAttempt;
use App\Models\User;
use App\Services\Fiscal\Mutations\FiscalMutationException;
use App\Services\Fiscal\Mutations\FiscalMutationService;
use App\Services\Fiscal\Mutations\MutationPreflightResult;
use App\Services\Serpro\Catalog\OfficialServiceCatalogManifest;
use App\Support\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;

final readonly class GenerateMeiDasAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private FindFiscalClientAction $findClient,
        private FiscalMutationService $mutations,
        private OfficialServiceCatalogManifest $manifest,
    ) {}

    public function preflight(User $actor, GenerateMeiDasData $data): MutationPreflightResult
    {
        $tenant = $this->currentTenant->tenant();
        $client = $this->requireClient($data->clientId);
        $operationKey = $this->operationKey($data->outputFormat);
        $operation = $this->officialOperation($operationKey);

        return $this->mutations->preflight(
            tenant: $tenant,
            client: $client,
            user: $actor,
            solutionCode: (string) $operation['id_sistema'],
            serviceCode: (string) $operation['id_sistema'],
            operationCode: (string) $operation['id_servico'],
            operationKey: $operationKey,
            competencePeriodKey: $this->competenceKey($data->competencies),
            idempotencyKey: $data->idempotencyKey,
            requestPayload: [
                'competencies' => $data->competencies,
                'due_date' => $data->dueDate,
                'output_format' => $data->outputFormat,
            ],
            module: 'simples_mei',
        );
    }

    /**
     * @return array{mutation: array<string, mixed>, attempt: array<string, mixed>|null, status: int}
     */
    public function execute(User $actor, GenerateMeiDasData $data): array
    {
        $tenant = $this->currentTenant->tenant();
        $client = $this->requireClient($data->clientId);
        $operationKey = $this->operationKey($data->outputFormat);
        $officialOperation = $this->officialOperation($operationKey);

        try {
            $operation = $this->mutations->execute(
                tenant: $tenant,
                client: $client,
                user: $actor,
                solutionCode: (string) $officialOperation['id_sistema'],
                serviceCode: (string) $officialOperation['id_sistema'],
                operationCode: (string) $officialOperation['id_servico'],
                operationKey: $operationKey,
                confirmationPhrase: (string) $data->confirmationPhrase,
                confirmed: true,
                competencePeriodKey: $this->competenceKey($data->competencies),
                idempotencyKey: $data->idempotencyKey,
                preflightToken: (string) $data->preflightToken,
                requestPayload: [
                    'competencies' => $data->competencies,
                    'due_date' => $data->dueDate,
                    'output_format' => $data->outputFormat,
                ],
                module: 'simples_mei',
            );
        } catch (FiscalMutationException $error) {
            throw new HttpResponseException(response()->json(
                $error->toArray(),
                $error->httpStatus(),
            ));
        }

        $attempt = MeiAutomationAttempt::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('fiscal_mutation_operation_id', $operation->id)
            ->latest('id')
            ->first();

        return [
            'mutation' => $this->publicMutation($operation),
            'attempt' => $attempt?->toPublicArray(),
            'status' => $operation->status === FiscalMutationStatus::Sent ? 202 : 201,
        ];
    }

    private function requireClient(int $clientId): Client
    {
        $client = $this->findClient->handle($this->currentTenant->tenant(), $clientId);
        if ($client === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'Cliente não encontrado no escritório atual.',
                'code' => 'CLIENT_NOT_FOUND',
            ], 404));
        }

        return $client;
    }

    /** @param list<string> $competencies */
    private function competenceKey(array $competencies): string
    {
        if (count($competencies) === 1) {
            return $competencies[0];
        }

        return 'MULTI:'.substr(hash('sha256', implode('|', $competencies)), 0, 12);
    }

    private function operationKey(string $outputFormat): string
    {
        return strtoupper($outputFormat) === 'BARCODE'
            ? 'pgmei.gerardascodbarra'
            : 'pgmei.gerardaspdf';
    }

    /** @return array<string, mixed> */
    private function officialOperation(string $operationKey): array
    {
        return $this->manifest->findByOperationKey($this->manifest->load(), $operationKey);
    }

    /** @return array<string, mixed> */
    private function publicMutation(FiscalMutationOperation $operation): array
    {
        $data = $operation->toPublicArray();
        unset($data['tenant_id']);

        return $data;
    }
}

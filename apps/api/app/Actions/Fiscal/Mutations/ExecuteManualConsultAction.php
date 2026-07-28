<?php

namespace App\Actions\Fiscal\Mutations;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\DTO\Fiscal\Mutations\ManualConsultExecuteData;
use App\Enums\TenantPermission;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\ManualConsult\ManualConsultExecutionService;
use App\Services\Fiscal\ManualConsult\ManualConsultNotReadyException;
use App\Support\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class ExecuteManualConsultAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantAuthorization $authorization,
        private FindFiscalClientAction $findClient,
        private ManualConsultExecutionService $execution,
    ) {}

    /**
     * @return array{payload: array<string, mixed>, status: int}
     */
    public function handle(User $actor, ManualConsultExecuteData $data): array
    {
        $tenant = $this->currentTenant->tenant();
        $client = $this->findClient->handle($tenant, $data->clientId);
        if ($client === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'Cliente não encontrado no escritório atual.',
                'code' => 'CLIENT_NOT_FOUND',
            ], 404));
        }

        $this->assertCanWrite($actor, $client);

        try {
            $payload = $this->execution->execute(
                tenant: $tenant,
                client: $client,
                actionId: $data->actionId,
                params: $data->params,
                confirmed: $data->confirmed,
                actorUserId: $actor->id,
            );
        } catch (ManualConsultNotReadyException $e) {
            throw new HttpResponseException(response()->json([
                'message' => $e->getMessage(),
                'code' => $e->eligibility->value,
            ], 422));
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpException $e) {
            throw new HttpResponseException(response()->json([
                'message' => $e->getMessage(),
                'code' => 'MANUAL_CONSULT_REJECTED',
            ], $e->getStatusCode()));
        } catch (\Throwable $e) {
            Log::warning('manual_consult.execution_error', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            throw new HttpResponseException(response()->json([
                'message' => 'Consulta manual indisponível.',
                'code' => 'MANUAL_CONSULT_ERROR',
            ], 500));
        }

        return [
            'payload' => $payload,
            'status' => ($payload['async'] ?? false) ? 202 : 201,
        ];
    }

    private function assertCanWrite(User $actor, Client $client): void
    {
        if (! $this->authorization->allows($actor, TenantPermission::FiscalSyncTrigger, $client)) {
            throw new AuthorizationException('Sem permissão de sincronização.');
        }
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Actions\Serpro\AcceptSerproExternalGateAction;
use App\Actions\Serpro\ActivateSerproCredentialVersionAction;
use App\Actions\Serpro\RegisterSerproCredentialVersionAction;
use App\Actions\Serpro\TestSerproCredentialConnectionAction;
use App\Actions\Serpro\UpdateSerproUsageLimitsAction;
use App\Actions\Serpro\VerifySerproCredentialVersionAction;
use App\Enums\SerproEnvironment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\AcceptSerproExternalGateRequest;
use App\Http\Requests\Platform\ActivateSerproCredentialVersionRequest;
use App\Http\Requests\Platform\ExecuteSerproCredentialOperationRequest;
use App\Http\Requests\Platform\FilterSerproEnvironmentRequest;
use App\Http\Requests\Platform\StoreSerproCredentialVersionRequest;
use App\Http\Requests\Platform\UpdateSerproUsageLimitsRequest;
use App\Http\Resources\SerproCredentialConnectionResultResource;
use App\Http\Resources\SerproCredentialVersionResource;
use App\Http\Resources\SerproExternalGateResource;
use App\Http\Resources\SerproUsageLimitsUpdateResultResource;
use App\Models\SerproCredentialVersion;
use App\Services\Serpro\SerproPlatformConfigurationService;
use Illuminate\Http\JsonResponse;

/**
 * Configuração global SERPRO (PLATFORM_ADMIN / Proprietário).
 * Sem Tenant context; respostas sempre sanitizadas; sem transporte fiscal.
 */
class SerproPlatformConfigurationController extends Controller
{
    public function __construct(
        private readonly SerproPlatformConfigurationService $configuration,
        private readonly RegisterSerproCredentialVersionAction $registerCredential,
        private readonly VerifySerproCredentialVersionAction $verifyCredential,
        private readonly TestSerproCredentialConnectionAction $testCredentialConnection,
        private readonly ActivateSerproCredentialVersionAction $activateCredential,
        private readonly AcceptSerproExternalGateAction $acceptExternalGate,
        private readonly UpdateSerproUsageLimitsAction $updateLimits,
    ) {}

    public function show(FilterSerproEnvironmentRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->configuration->getConfiguration(
                $request->toDto()->environmentOr(SerproEnvironment::Trial),
            ),
        ]);
    }

    public function storeCredentialVersion(StoreSerproCredentialVersionRequest $request): JsonResponse
    {
        $resource = SerproCredentialVersionResource::make(
            ($this->registerCredential)($request->toDto(), $request->actor(), $request),
        );

        return $resource->response()->setStatusCode(201);
    }

    public function verifyCredentialVersion(
        ExecuteSerproCredentialOperationRequest $request,
        SerproCredentialVersion $serproCredentialVersion,
    ): SerproCredentialVersionResource {
        return SerproCredentialVersionResource::make(
            ($this->verifyCredential)($serproCredentialVersion, $request->actor(), $request),
        );
    }

    public function testConnection(
        ExecuteSerproCredentialOperationRequest $request,
        SerproCredentialVersion $serproCredentialVersion,
    ): SerproCredentialConnectionResultResource {
        return SerproCredentialConnectionResultResource::make(
            ($this->testCredentialConnection)(
                $serproCredentialVersion,
                $request->actor(),
                $request,
            ),
        );
    }

    public function activateCredentialVersion(
        ActivateSerproCredentialVersionRequest $request,
        SerproCredentialVersion $serproCredentialVersion,
    ): SerproCredentialVersionResource {
        return SerproCredentialVersionResource::make(
            ($this->activateCredential)(
                $serproCredentialVersion,
                $request->toDto(),
                $request->actor(),
                $request,
            ),
        );
    }

    public function updateExternalGate(
        AcceptSerproExternalGateRequest $request,
        string $gate,
    ): SerproExternalGateResource {
        return SerproExternalGateResource::make(
            ($this->acceptExternalGate)(
                $request->toDto($gate),
                $request->actor(),
                $request,
            ),
        );
    }

    public function updateUsageLimits(
        UpdateSerproUsageLimitsRequest $request,
    ): SerproUsageLimitsUpdateResultResource {
        return SerproUsageLimitsUpdateResultResource::make(
            ($this->updateLimits)($request->toDto(), $request->actor(), $request),
        );
    }
}

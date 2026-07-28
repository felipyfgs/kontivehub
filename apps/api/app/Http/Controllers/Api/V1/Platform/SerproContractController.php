<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Actions\Serpro\ResetSerproCircuitBreakerAction;
use App\Actions\Serpro\UpdateSerproKillSwitchAction;
use App\Enums\SerproEnvironment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\FilterSerproEnvironmentRequest;
use App\Http\Requests\Platform\ResetSerproCircuitBreakerRequest;
use App\Http\Requests\Platform\UpdateSerproKillSwitchRequest;
use App\Http\Resources\SerproContractResource;
use App\Models\SerproContract;
use App\Services\Serpro\SerproCatalogService;
use App\Services\Serpro\SerproContractService;
use App\Services\Serpro\SerproHealthService;
use App\Services\Serpro\SerproKillSwitchService;
use Illuminate\Http\JsonResponse;

/**
 * Administração global do contrato SERPRO (PLATFORM_ADMIN).
 * Nunca retorna PFX, senha, PEM, Consumer Secret, tokens ou Termo XML.
 */
class SerproContractController extends Controller
{
    public function __construct(
        private readonly SerproContractService $contracts,
        private readonly SerproHealthService $health,
        private readonly SerproCatalogService $catalog,
        private readonly SerproKillSwitchService $killSwitch,
        private readonly UpdateSerproKillSwitchAction $updateKillSwitch,
        private readonly ResetSerproCircuitBreakerAction $resetCircuitBreaker,
    ) {}

    public function index(FilterSerproEnvironmentRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->contracts->listSanitized($request->toDto()->environment),
        ]);
    }

    public function show(SerproContract $serproContract): SerproContractResource
    {
        return SerproContractResource::make($serproContract);
    }

    public function health(FilterSerproEnvironmentRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->health->globalHealth($request->toDto()->environment),
        ]);
    }

    public function catalog(FilterSerproEnvironmentRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->catalog->listForEnvironment(
                $request->toDto()->environmentOr(SerproEnvironment::Trial),
            ),
        ]);
    }

    public function killSwitch(UpdateSerproKillSwitchRequest $request): JsonResponse
    {
        return response()->json(
            ($this->updateKillSwitch)($request->toDto(), $request->actor(), $request),
        );
    }

    public function killSwitchStatus(): JsonResponse
    {
        return response()->json(['data' => $this->killSwitch->status()]);
    }

    public function breakerReset(ResetSerproCircuitBreakerRequest $request): JsonResponse
    {
        return response()->json([
            'data' => ($this->resetCircuitBreaker)($request->toDto(), $request->actor()),
        ]);
    }
}

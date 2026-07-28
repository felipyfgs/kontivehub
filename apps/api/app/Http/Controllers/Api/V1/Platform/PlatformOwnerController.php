<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Actions\Platform\ShowPlatformOwnerAction;
use App\Actions\Platform\UpdatePlatformOwnerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\UpdatePlatformOwnerRequest;
use Illuminate\Http\JsonResponse;

/**
 * Superfície singular do Proprietário da instalação (GET/PATCH).
 * Não cria PlatformMembership; não substitui o fluxo host de recuperação.
 */
class PlatformOwnerController extends Controller
{
    public function __construct(
        private readonly ShowPlatformOwnerAction $showOwner,
        private readonly UpdatePlatformOwnerAction $updateOwner,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => ($this->showOwner)(),
        ]);
    }

    public function update(UpdatePlatformOwnerRequest $request): JsonResponse
    {
        $data = ($this->updateOwner)($request->toDto(), $request->actor());

        return response()
            ->json(['data' => $data])
            ->header('Cache-Control', 'no-store');
    }
}

<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Platform\PlatformOwnerException;
use App\Services\Platform\PlatformOwnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Superfície singular do Proprietário da instalação (GET/PATCH).
 * Não cria PlatformMembership; não substitui o fluxo host de recuperação.
 */
class PlatformOwnerController extends Controller
{
    public function __construct(
        private readonly PlatformOwnerService $owners,
    ) {}

    public function show(): JsonResponse
    {
        try {
            $pm = $this->owners->requireMembership();
        } catch (PlatformOwnerException $e) {
            return $this->ownerError($e);
        }

        return response()->json([
            'data' => $this->owners->sanitize($pm),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'default_office_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ]);

        if ($validated === []) {
            return response()->json([
                'message' => 'Nenhum campo para atualizar.',
                'code' => 'platform_owner_invalid',
            ], 422);
        }

        try {
            $result = $this->owners->updateOwner($validated, $actor);
        } catch (PlatformOwnerException $e) {
            return $this->ownerError($e);
        }

        return response()
            ->json(['data' => $this->owners->sanitize($result['membership'])])
            ->header('Cache-Control', 'no-store');
    }

    private function ownerError(PlatformOwnerException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->errorCode,
        ], $e->status);
    }
}

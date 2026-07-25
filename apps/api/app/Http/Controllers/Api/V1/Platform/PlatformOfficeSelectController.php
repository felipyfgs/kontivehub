<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\OfficeRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Platform\PlatformOfficeSelectService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Seletor global de escritório (PLATFORM_ADMIN, modo privilegiado).
 * Fora de EnsureOfficeContext; office_id de destino validado no serviço (não cria membership).
 */
class PlatformOfficeSelectController extends Controller
{
    public function __construct(
        private readonly PlatformOfficeSelectService $selector,
        private readonly CurrentOffice $currentOffice,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->selector->listEnvelope($user),
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->selector->current($user),
        ]);
    }

    public function select(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'office_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $office = $this->selector->select($user, (int) $validated['office_id'], $request);
        } catch (HttpException $e) {
            $code = $e->getHeaders()['X-Error-Code'] ?? null;

            return response()->json([
                'message' => $e->getMessage(),
                'code' => is_string($code) ? $code : 'http_error',
            ], $e->getStatusCode());
        }

        return response()->json([
            'data' => [
                'office' => [
                    'id' => $office->id,
                    'name' => $office->name,
                    'slug' => $office->slug,
                ],
                'role' => $this->currentOffice->role()?->value ?? OfficeRole::Admin->value,
                'access_mode' => $this->currentOffice->accessMode()?->value,
                'real_office_role' => $this->currentOffice->realOfficeRole()?->value,
                'has_real_membership' => $this->currentOffice->hasRealMembership(),
                'default_office_id' => $this->currentOffice->defaultOfficeId($user),
                'actor_user_id' => $user->id,
            ],
        ]);
    }

    public function clear(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->selector->clear($user, $request);

        return response()->json([
            'data' => [
                'cleared' => true,
                'access_mode' => null,
                'office' => null,
            ],
        ]);
    }
}

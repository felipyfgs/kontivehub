<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reconfirmação de senha (janela curta) para ações sensíveis privilegiadas.
 */
class ConfirmPasswordController extends Controller
{
    public function __construct(
        private readonly RecentPasswordConfirmationGate $gate,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'password' => ['required', 'string'],
        ]);

        try {
            $this->gate->confirmWithPassword($user, $data['password'], $request);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'PASSWORD_INVALID',
            ], 422);
        }

        return response()->json([
            'data' => [
                'confirmed' => true,
                'window_minutes' => $this->gate->windowMinutes(),
                'seconds_remaining' => $this->gate->secondsRemaining($request, $user),
            ],
        ]);
    }
}

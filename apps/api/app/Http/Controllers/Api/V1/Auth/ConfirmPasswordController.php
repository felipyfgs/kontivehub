<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\ConfirmPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmPasswordRequest;
use Illuminate\Http\JsonResponse;

/**
 * Reconfirmação de senha (janela curta) para ações sensíveis privilegiadas.
 */
class ConfirmPasswordController extends Controller
{
    public function __construct(
        private readonly ConfirmPasswordAction $confirmPassword,
    ) {}

    public function __invoke(ConfirmPasswordRequest $request): JsonResponse
    {
        $result = ($this->confirmPassword)(
            $request->actor(),
            $request->toDto(),
            $request,
        );

        return response()->json([
            'data' => $result->toArray(),
        ]);
    }
}

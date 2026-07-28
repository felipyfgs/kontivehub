<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\UpdateAccountAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdateAccountRequest;
use Illuminate\Http\JsonResponse;

/** Atualiza somente a identidade global do próprio usuário autenticado. */
class UpdateAccountController extends Controller
{
    public function __construct(
        private readonly UpdateAccountAction $updateAccount,
    ) {}

    public function __invoke(UpdateAccountRequest $request): JsonResponse
    {
        $user = ($this->updateAccount)($request->actor(), $request->toDto());

        return response()
            ->json([
                'data' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ],
            ])
            ->header('Cache-Control', 'no-store');
    }
}

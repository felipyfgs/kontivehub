<?php

namespace App\Http\Controllers\Api\V1\Activation;

use App\Http\Controllers\Controller;
use App\Services\Activation\ActivationCompletionService;
use App\Services\Activation\ActivationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * Endpoints públicos de inspeção/conclusão de ativação e primeiro acesso.
 */
class PublicActivationController extends Controller
{
    public function __construct(
        private readonly ActivationCompletionService $completion,
    ) {}

    public function inspect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:32', 'max:256'],
        ]);

        $data = $this->completion->inspectLink($validated['token']);

        return $this->noStoreJson(['data' => $data]);
    }

    public function complete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:32', 'max:256'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        try {
            $result = $this->completion->completeLink(
                $validated['token'],
                $validated['password'],
            );
        } catch (ActivationException $e) {
            return $this->activationError($e);
        }

        return $this->noStoreJson([
            'data' => [
                'authenticated' => true,
                'user_id' => $result['user']->id,
                'purpose' => $result['purpose'],
            ],
        ]);
    }

    public function completeFirstAccess(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'temporary_password' => ['required', 'string', 'max:128'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        try {
            $result = $this->completion->completeFirstAccess(
                $validated['email'],
                $validated['temporary_password'],
                $validated['password'],
            );
        } catch (ActivationException $e) {
            return $this->activationError($e);
        }

        return $this->noStoreJson([
            'data' => [
                'authenticated' => true,
                'user_id' => $result['user']->id,
                'purpose' => $result['purpose'],
            ],
        ]);
    }

    private function activationError(ActivationException $e): JsonResponse
    {
        return $this->noStoreJson([
            'message' => $e->getMessage(),
            'code' => $e->errorCode,
        ], $e->status);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function noStoreJson(array $payload, int $status = 200): JsonResponse
    {
        return response()
            ->json($payload, $status)
            ->header('Cache-Control', 'no-store');
    }
}

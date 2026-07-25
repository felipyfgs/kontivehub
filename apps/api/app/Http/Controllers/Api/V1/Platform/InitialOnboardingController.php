<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Services\Platform\InitialOnboardingException;
use App\Services\Platform\InitialOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

/**
 * Onboarding público do primeiro PLATFORM_ADMIN (instalação vazia).
 * Token somente no body; respostas Cache-Control: no-store.
 */
class InitialOnboardingController extends Controller
{
    public function __construct(
        private readonly InitialOnboardingService $onboarding,
    ) {}

    public function status(): JsonResponse
    {
        return $this->noStoreJson([
            'data' => [
                'available' => $this->onboarding->available(),
            ],
        ]);
    }

    public function complete(Request $request): JsonResponse
    {
        if (app()->environment('production') && ! $request->secure()) {
            return $this->onboardingError(InitialOnboardingException::secureTransportRequired());
        }

        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'onboarding_token' => ['required', 'string', 'min:32', 'max:512'],
        ]);

        try {
            $result = $this->onboarding->complete(
                $validated['organization_name'],
                $validated['email'],
                $validated['password'],
                $validated['onboarding_token'],
            );
        } catch (InitialOnboardingException $e) {
            return $this->onboardingError($e);
        }

        Auth::guard('web')->login($result['user']);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return $this->noStoreJson([
            'data' => [
                'authenticated' => true,
                'user_id' => $result['user']->id,
                'redirect' => '/admin/offices/new',
                'platform_organization_name' => $result['settings']->organization_name,
            ],
        ], 201);
    }

    private function onboardingError(InitialOnboardingException $e): JsonResponse
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
            ->header('Cache-Control', 'no-store, private');
    }
}

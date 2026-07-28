<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Actions\Platform\CompleteInitialOnboardingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\CompleteInitialOnboardingRequest;
use App\Services\Platform\InitialOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Onboarding público do primeiro PLATFORM_ADMIN (instalação vazia).
 * Token somente no body; respostas Cache-Control: no-store.
 */
class InitialOnboardingController extends Controller
{
    public function __construct(
        private readonly InitialOnboardingService $onboarding,
        private readonly CompleteInitialOnboardingAction $completeOnboarding,
    ) {}

    public function status(): JsonResponse
    {
        return $this->noStoreJson([
            'data' => [
                'available' => $this->onboarding->available(),
            ],
        ]);
    }

    public function complete(CompleteInitialOnboardingRequest $request): JsonResponse
    {
        $result = ($this->completeOnboarding)($request->toDto(), $request);

        Auth::guard('web')->login($result['user']);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return $this->noStoreJson([
            'data' => [
                'authenticated' => true,
                'user_id' => $result['user']->id,
                'redirect' => '/admin/tenants/new',
                'platform_organization_name' => $result['settings']->organization_name,
            ],
        ], 201);
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

<?php

namespace App\Actions\Platform;

use App\DTO\Platform\InitialOnboardingData;
use App\Exceptions\InitialOnboardingApiException;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Platform\InitialOnboardingException;
use App\Services\Platform\InitialOnboardingService;
use Illuminate\Http\Request;

final readonly class CompleteInitialOnboardingAction
{
    public function __construct(
        private InitialOnboardingService $onboarding,
    ) {}

    /** @return array{user: User, settings: PlatformSetting} */
    public function __invoke(InitialOnboardingData $data, Request $request): array
    {
        if (app()->environment('production') && ! $request->secure()) {
            throw InitialOnboardingApiException::fromDomain(
                InitialOnboardingException::secureTransportRequired(),
            );
        }

        try {
            return $this->onboarding->complete(
                $data->organizationName,
                $data->email,
                $data->password,
                $data->token,
            );
        } catch (InitialOnboardingException $error) {
            throw InitialOnboardingApiException::fromDomain($error);
        }
    }
}

<?php

namespace App\Actions\Platform;

use App\DTO\Platform\ActivationDeliveryResult;
use App\DTO\Platform\PendingTenantCreationData;
use App\Exceptions\ActivationApiException;
use App\Models\User;
use App\Services\Activation\ActivationException;
use App\Services\Activation\CreatePendingTenantService;

final readonly class CreatePendingTenantAction
{
    public function __construct(
        private CreatePendingTenantService $createTenant,
    ) {}

    public function __invoke(
        PendingTenantCreationData $data,
        User $actor,
    ): ActivationDeliveryResult {
        try {
            $payload = $this->createTenant->create($data->toServicePayload(), $actor);
        } catch (ActivationException $error) {
            throw ActivationApiException::fromDomain($error);
        }

        return new ActivationDeliveryResult(
            payload: $payload,
            httpStatus: ($payload['credential_delivery'] ?? null) === 'regeneration_required'
                ? 200
                : 201,
        );
    }
}

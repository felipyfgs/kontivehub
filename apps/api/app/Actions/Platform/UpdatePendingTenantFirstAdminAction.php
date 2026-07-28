<?php

namespace App\Actions\Platform;

use App\DTO\Platform\ActivationDeliveryResult;
use App\DTO\Platform\PendingTenantFirstAdminData;
use App\Exceptions\ActivationApiException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Activation\ActivationException;
use App\Services\Activation\CorrectPendingRecipientService;

final readonly class UpdatePendingTenantFirstAdminAction
{
    public function __construct(
        private CorrectPendingRecipientService $correctRecipient,
    ) {}

    public function __invoke(
        Tenant $tenant,
        PendingTenantFirstAdminData $data,
        User $actor,
    ): ActivationDeliveryResult {
        try {
            return new ActivationDeliveryResult(
                payload: $this->correctRecipient->correctTenantFirstAdmin(
                    $tenant,
                    $data->name,
                    $data->email,
                    $data->method,
                    $actor,
                ),
            );
        } catch (ActivationException $error) {
            throw ActivationApiException::fromDomain($error);
        }
    }
}

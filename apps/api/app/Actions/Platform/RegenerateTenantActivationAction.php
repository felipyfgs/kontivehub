<?php

namespace App\Actions\Platform;

use App\DTO\Platform\ActivationDeliveryResult;
use App\DTO\Platform\ActivationMethodData;
use App\Enums\ActivationPurpose;
use App\Enums\TenantRole;
use App\Exceptions\ActivationApiException;
use App\Models\AccountActivation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Activation\ActivationException;
use App\Services\Activation\RegenerateActivationService;

final readonly class RegenerateTenantActivationAction
{
    public function __construct(
        private RegenerateActivationService $regenerate,
    ) {}

    public function __invoke(
        Tenant $tenant,
        ActivationMethodData $data,
        User $actor,
    ): ActivationDeliveryResult {
        try {
            $membership = TenantMembership::query()
                ->where('tenant_id', $tenant->id)
                ->where('role', TenantRole::TenantAdmin)
                ->orderBy('id')
                ->first();

            if ($membership === null) {
                throw ActivationException::notFound('Primeiro administrador não encontrado.');
            }

            $activation = AccountActivation::query()
                ->where('tenant_membership_id', $membership->id)
                ->where('purpose', ActivationPurpose::TenantFirstAdmin)
                ->whereNull('consumed_at')
                ->orderByDesc('generation')
                ->orderByDesc('id')
                ->first();

            if ($activation === null) {
                throw ActivationException::notFound('Nenhuma ativação pendente.');
            }

            return new ActivationDeliveryResult(
                payload: $this->regenerate->regenerate(
                    $activation,
                    $data->method,
                    $actor,
                ),
            );
        } catch (ActivationException $error) {
            throw ActivationApiException::fromDomain($error);
        }
    }
}

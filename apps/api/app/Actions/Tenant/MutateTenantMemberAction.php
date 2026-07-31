<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\MemberCreationData;
use App\DTO\Tenant\MemberRecipientData;
use App\Enums\ActivationMethod;
use App\Enums\TenantRole;
use App\Exceptions\ActivationApiException;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Activation\ActivationException;
use App\Services\Activation\TenantTeamService;
use App\Support\CurrentTenant;

final readonly class MutateTenantMemberAction
{
    public function __construct(
        private TenantTeamService $team,
        private CurrentTenant $currentTenant,
    ) {}

    /** @return array<string, mixed> */
    public function create(
        User $actor,
        MemberCreationData $data,
    ): array {
        return $this->translate(
            fn (): array => $this->team->createMember(
                $actor,
                $data->toServiceInput(),
            ),
        );
    }

    /** @return array<string, mixed> */
    public function changeRole(
        User $actor,
        int $membershipId,
        TenantRole $role,
    ): array {
        return $this->translate(
            fn (): array => $this->team->changeRole(
                $actor,
                $this->membership($membershipId),
                $role,
            ),
        );
    }

    /** @return array<string, mixed> */
    public function correctRecipient(
        User $actor,
        int $membershipId,
        MemberRecipientData $data,
    ): array {
        return $this->translate(
            fn (): array => $this->team->correctRecipient(
                $actor,
                $this->membership($membershipId),
                $data->name,
                $data->email,
                $data->method,
            ),
        );
    }

    /** @return array<string, mixed> */
    public function deactivate(User $actor, int $membershipId): array
    {
        return $this->translate(
            fn (): array => $this->team->deactivate(
                $actor,
                $this->membership($membershipId),
            ),
        );
    }

    /** @return array<string, mixed> */
    public function reactivate(
        User $actor,
        int $membershipId,
        ActivationMethod $method,
    ): array {
        return $this->translate(
            fn (): array => $this->team->reactivate(
                $actor,
                $this->membership($membershipId),
                $method,
            ),
        );
    }

    /** @return array<string, mixed> */
    public function regenerate(
        User $actor,
        int $membershipId,
        ActivationMethod $method,
    ): array {
        return $this->translate(
            fn (): array => $this->team->regenerateActivation(
                $actor,
                $this->membership($membershipId),
                $method,
            ),
        );
    }

    private function membership(int $membershipId): TenantMembership
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;

        return TenantMembership::query()
            ->where('tenant_id', $tenantId)
            ->find($membershipId)
            ?? throw ActivationApiException::fromDomain(
                ActivationException::notFound(
                    'Membro não encontrado neste escritório.',
                ),
            );
    }

    /**
     * @param  callable(): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    private function translate(callable $operation): array
    {
        try {
            return $operation();
        } catch (ActivationException $error) {
            throw ActivationApiException::fromDomain($error);
        }
    }
}

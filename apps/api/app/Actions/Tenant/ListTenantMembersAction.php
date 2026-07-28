<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\TenantMemberListData;
use App\Exceptions\ActivationApiException;
use App\Models\User;
use App\Services\Activation\ActivationException;
use App\Services\Activation\TenantTeamService;
use App\Support\CurrentTenant;

final readonly class ListTenantMembersAction
{
    public function __construct(
        private TenantTeamService $team,
        private CurrentTenant $currentTenant,
    ) {}

    public function __invoke(User $actor): TenantMemberListData
    {
        try {
            $members = $this->team->list($actor);
        } catch (ActivationException $error) {
            throw ActivationApiException::fromDomain($error);
        }

        $tenant = $this->currentTenant->tenant();

        return new TenantMemberListData(
            members: $members,
            occupiedSeats: $this->team->occupiedSeats($tenant),
            maxUsers: $tenant->subscription?->max_users,
        );
    }
}

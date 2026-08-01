<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\MemberListData;
use App\Exceptions\ActivationApiException;
use App\Models\User;
use App\Services\Activation\ActivationException;
use App\Services\Activation\TenantTeamService;
use App\Support\CurrentTenant;

final readonly class ListMembersAction
{
    public function __construct(
        private TenantTeamService $team,
        private CurrentTenant $currentTenant,
    ) {}

    public function __invoke(User $actor): MemberListData
    {
        try {
            $members = $this->team->list($actor);
        } catch (ActivationException $error) {
            throw ActivationApiException::fromDomain($error);
        }

        $tenant = $this->currentTenant->tenant();

        return new MemberListData(
            members: $members,
            occupiedSeats: $this->team->occupiedSeats($tenant),
            maxUsers: $tenant->subscription?->max_users,
        );
    }
}

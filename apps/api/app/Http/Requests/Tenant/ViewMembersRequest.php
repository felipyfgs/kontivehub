<?php

namespace App\Http\Requests\Tenant;

use App\Models\User;
use App\Policies\TenantMemberPolicy;

final class ViewMembersRequest extends MemberRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }

    protected function authorizeMemberOperation(
        TenantMemberPolicy $policy,
        User $actor,
    ): bool {
        return $policy->viewAny($actor);
    }
}

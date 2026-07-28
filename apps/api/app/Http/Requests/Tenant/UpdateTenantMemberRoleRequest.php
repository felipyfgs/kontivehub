<?php

namespace App\Http\Requests\Tenant;

use App\Enums\TenantRole;
use App\Models\User;
use App\Policies\TenantMemberPolicy;
use Illuminate\Validation\Rule;

final class UpdateTenantMemberRoleRequest extends TenantMemberRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::enum(TenantRole::class)],
        ];
    }

    public function role(): TenantRole
    {
        return TenantRole::from((string) $this->validated('role'));
    }

    protected function authorizeMemberOperation(
        TenantMemberPolicy $policy,
        User $actor,
    ): bool {
        $role = TenantRole::tryFrom((string) $this->input('role'));

        return $role === TenantRole::TenantAdmin
            ? $policy->assignAdmin($actor)
            : $policy->manage($actor);
    }
}

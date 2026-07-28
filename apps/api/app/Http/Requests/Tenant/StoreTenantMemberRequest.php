<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\TenantMemberCreationData;
use App\Enums\ActivationMethod;
use App\Enums\TenantRole;
use App\Models\User;
use App\Policies\TenantMemberPolicy;
use Illuminate\Validation\Rule;

final class StoreTenantMemberRequest extends TenantMemberRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'string', Rule::enum(TenantRole::class)],
            'method' => ['required', 'string', Rule::enum(ActivationMethod::class)],
        ];
    }

    public function memberData(): TenantMemberCreationData
    {
        $validated = $this->validated();

        return new TenantMemberCreationData(
            name: (string) $validated['name'],
            email: (string) $validated['email'],
            role: TenantRole::from((string) $validated['role']),
            method: ActivationMethod::from((string) $validated['method']),
        );
    }

    protected function authorizeMemberOperation(
        TenantMemberPolicy $policy,
        User $actor,
    ): bool {
        return $policy->create($actor);
    }
}

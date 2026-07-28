<?php

namespace App\Http\Requests\Tenant;

use App\Enums\ActivationMethod;
use App\Models\User;
use App\Policies\TenantMemberPolicy;
use Illuminate\Validation\Rule;

final class ReactivateTenantMemberRequest extends TenantMemberRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'method' => ['sometimes', 'string', Rule::enum(ActivationMethod::class)],
        ];
    }

    public function deliveryMethod(): ActivationMethod
    {
        $method = $this->validated('method');

        return is_string($method)
            ? ActivationMethod::from($method)
            : ActivationMethod::ManualLink;
    }

    protected function authorizeMemberOperation(
        TenantMemberPolicy $policy,
        User $actor,
    ): bool {
        return $policy->manage($actor);
    }
}

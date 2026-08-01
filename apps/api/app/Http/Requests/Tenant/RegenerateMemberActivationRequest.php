<?php

namespace App\Http\Requests\Tenant;

use App\Enums\ActivationMethod;
use App\Models\User;
use App\Policies\TenantMemberPolicy;
use Illuminate\Validation\Rule;

final class RegenerateMemberActivationRequest extends MemberRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', Rule::enum(ActivationMethod::class)],
        ];
    }

    public function deliveryMethod(): ActivationMethod
    {
        return ActivationMethod::from((string) $this->validated('method'));
    }

    protected function authorizeMemberOperation(
        TenantMemberPolicy $policy,
        User $actor,
    ): bool {
        return $policy->manage($actor);
    }
}

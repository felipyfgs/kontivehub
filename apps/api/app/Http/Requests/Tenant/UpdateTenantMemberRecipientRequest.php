<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\TenantMemberRecipientData;
use App\Enums\ActivationMethod;
use App\Models\User;
use App\Policies\TenantMemberPolicy;
use Illuminate\Validation\Rule;

final class UpdateTenantMemberRecipientRequest extends TenantMemberRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'method' => ['required', 'string', Rule::enum(ActivationMethod::class)],
        ];
    }

    public function recipientData(): TenantMemberRecipientData
    {
        $validated = $this->validated();

        return new TenantMemberRecipientData(
            name: (string) $validated['name'],
            email: (string) $validated['email'],
            method: ActivationMethod::from((string) $validated['method']),
        );
    }

    protected function authorizeMemberOperation(
        TenantMemberPolicy $policy,
        User $actor,
    ): bool {
        return $policy->manage($actor);
    }
}

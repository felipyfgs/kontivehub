<?php

namespace App\Http\Requests\Platform;

use App\DTO\Platform\PendingTenantCreationData;
use App\DTO\Platform\TenantInstitutionalProfileData;
use App\Enums\ActivationMethod;
use App\Enums\SubscriptionPlan;
use App\Http\Requests\AuthenticatedRequest;
use App\Rules\ValidCnpj;
use Illuminate\Validation\Rule;

final class CreatePendingTenantRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        $profile = $this->input('profile');
        if (is_array($profile)
            && array_key_exists('cnpj', $profile)
            && trim((string) $profile['cnpj']) === ''
        ) {
            $profile['cnpj'] = null;
            $this->merge(['profile' => $profile]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'profile' => [
                'required',
                'array:cnpj,legal_name,institutional_email,institutional_phone',
            ],
            'profile.cnpj' => ['nullable', 'string', new ValidCnpj],
            'profile.legal_name' => ['required', 'string', 'max:255'],
            'profile.institutional_email' => ['required', 'email', 'max:255'],
            'profile.institutional_phone' => ['required', 'string', 'max:40'],
            'plan' => ['required', 'string', Rule::enum(SubscriptionPlan::class)],
            'admin_name' => ['required', 'string', 'max:255'],
            'admin_email' => ['required', 'email', 'max:255'],
            'method' => ['required', 'string', Rule::enum(ActivationMethod::class)],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:128'],
        ];
    }

    public function toDto(): PendingTenantCreationData
    {
        $validated = $this->validated();
        /** @var array<string, mixed> $profile */
        $profile = $validated['profile'];

        return new PendingTenantCreationData(
            name: (string) $validated['name'],
            profile: new TenantInstitutionalProfileData(
                cnpj: isset($profile['cnpj']) ? (string) $profile['cnpj'] : null,
                legalName: (string) $profile['legal_name'],
                institutionalEmail: (string) $profile['institutional_email'],
                institutionalPhone: (string) $profile['institutional_phone'],
            ),
            plan: SubscriptionPlan::from((string) $validated['plan']),
            adminName: (string) $validated['admin_name'],
            adminEmail: (string) $validated['admin_email'],
            method: ActivationMethod::from((string) $validated['method']),
            idempotencyKey: (string) $validated['idempotency_key'],
        );
    }
}

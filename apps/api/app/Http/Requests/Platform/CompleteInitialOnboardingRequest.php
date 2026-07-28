<?php

namespace App\Http\Requests\Platform;

use App\DTO\Platform\InitialOnboardingData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class CompleteInitialOnboardingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
            'onboarding_token' => ['required', 'string', 'min:32', 'max:512'],
        ];
    }

    public function toDto(): InitialOnboardingData
    {
        return new InitialOnboardingData(
            organizationName: (string) $this->validated('organization_name'),
            email: (string) $this->validated('email'),
            password: (string) $this->validated('password'),
            token: (string) $this->validated('onboarding_token'),
        );
    }
}

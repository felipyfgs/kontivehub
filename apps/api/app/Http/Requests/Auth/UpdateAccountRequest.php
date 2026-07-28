<?php

namespace App\Http\Requests\Auth;

use App\DTO\Auth\AccountIdentityData;
use App\Http\Requests\AuthenticatedRequest;
use Illuminate\Validation\Rule;

final class UpdateAccountRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $email = $this->input('email');

        $this->merge([
            'name' => is_string($name) ? trim($name) : $name,
            'email' => is_string($email) ? mb_strtolower(trim($email)) : $email,
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->actor()->id),
            ],
        ];
    }

    public function toDto(): AccountIdentityData
    {
        return new AccountIdentityData(
            name: (string) $this->validated('name'),
            email: (string) $this->validated('email'),
        );
    }
}

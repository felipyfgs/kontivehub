<?php

namespace App\Http\Requests\Auth;

use App\DTO\Auth\PasswordConfirmationData;
use App\Http\Requests\AuthenticatedRequest;

final class ConfirmPasswordRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string'],
        ];
    }

    public function toDto(): PasswordConfirmationData
    {
        return new PasswordConfirmationData(
            password: (string) $this->validated('password'),
        );
    }
}

<?php

namespace App\Http\Requests\Sefaz;

use App\Http\Requests\AuthenticatedRequest;

final class IssueCteEmitterTokenRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ];
    }

    public function tokenName(): string
    {
        return (string) $this->validated('name');
    }

    public function expiresInDays(): ?int
    {
        $value = $this->validated('expires_in_days');

        return $value !== null ? (int) $value : null;
    }
}

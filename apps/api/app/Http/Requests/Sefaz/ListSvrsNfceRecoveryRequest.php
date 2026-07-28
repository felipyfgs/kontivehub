<?php

namespace App\Http\Requests\Sefaz;

use App\Http\Requests\AuthenticatedRequest;

final class ListSvrsNfceRecoveryRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'nullable', 'string', 'max:40'],
            'profile_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'client_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function status(): ?string
    {
        $value = $this->validated('status') ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function profileId(): ?int
    {
        $value = $this->validated('profile_id') ?? null;

        return $value !== null ? (int) $value : null;
    }

    public function clientId(): ?int
    {
        $value = $this->validated('client_id') ?? null;

        return $value !== null ? (int) $value : null;
    }

    public function perPage(): int
    {
        return min(100, max(1, (int) ($this->validated('per_page') ?? 20)));
    }
}

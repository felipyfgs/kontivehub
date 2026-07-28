<?php

namespace App\Http\Requests\Sefaz;

use App\Http\Requests\AuthenticatedRequest;

final class ResetSvrsNfceBreakerRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'scope' => ['required', 'in:global,root'],
            'client_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function scope(): string
    {
        return (string) $this->validated('scope');
    }

    public function clientId(): ?int
    {
        $value = $this->validated('client_id');

        return $value !== null ? (int) $value : null;
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}

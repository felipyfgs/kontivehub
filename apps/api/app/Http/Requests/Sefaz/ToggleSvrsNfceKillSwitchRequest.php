<?php

namespace App\Http\Requests\Sefaz;

use App\Http\Requests\AuthenticatedRequest;

final class ToggleSvrsNfceKillSwitchRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function active(): bool
    {
        return (bool) $this->validated('active');
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}

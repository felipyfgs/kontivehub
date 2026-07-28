<?php

namespace App\Http\Requests\Sefaz;

use App\Http\Requests\AuthenticatedRequest;

final class ExtendSvrsNfceEgressCooldownRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        foreach ([
            'min_interval',
            'max_exchanges',
            'url',
            'host',
            'headers',
            'cookie',
            'proxy',
            'next_probe_at',
        ] as $forbidden) {
            $this->request->remove($forbidden);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'additional_seconds' => ['required', 'integer', 'min:60', 'max:604800'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function additionalSeconds(): int
    {
        return (int) $this->validated('additional_seconds');
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}

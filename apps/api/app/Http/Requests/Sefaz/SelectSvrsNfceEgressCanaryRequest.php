<?php

namespace App\Http\Requests\Sefaz;

use App\Http\Requests\AuthenticatedRequest;

final class SelectSvrsNfceEgressCanaryRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        foreach ([
            'url',
            'host',
            'headers',
            'cookie',
            'proxy',
            'next_probe_at',
            'min_interval',
        ] as $forbidden) {
            $this->request->remove($forbidden);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'number_state_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function numberStateId(): int
    {
        return (int) $this->validated('number_state_id');
    }

    public function reason(): string
    {
        return (string) $this->validated('reason');
    }
}

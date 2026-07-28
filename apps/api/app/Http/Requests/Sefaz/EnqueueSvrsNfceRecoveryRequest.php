<?php

namespace App\Http\Requests\Sefaz;

use App\Http\Requests\AuthenticatedRequest;

final class EnqueueSvrsNfceRecoveryRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        // Ignorar campos de elevação de tenant/egress enviados pelo cliente
        foreach ([
            'tenant_id',
            'url',
            'host',
            'headers',
            'cookie',
            'credential_id',
            'vault_object_id',
        ] as $forbidden) {
            $this->request->remove($forbidden);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'number_state_id' => ['required', 'integer'],
        ];
    }

    public function numberStateId(): int
    {
        return (int) $this->validated('number_state_id');
    }
}

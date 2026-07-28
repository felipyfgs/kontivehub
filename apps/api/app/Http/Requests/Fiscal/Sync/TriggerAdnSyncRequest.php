<?php

namespace App\Http\Requests\Fiscal\Sync;

use App\Http\Requests\AuthenticatedRequest;

final class TriggerAdnSyncRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'establishment_id' => ['required', 'integer'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function establishmentId(): int
    {
        return (int) $this->validated('establishment_id');
    }
}

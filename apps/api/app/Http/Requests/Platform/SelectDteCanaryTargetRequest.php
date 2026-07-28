<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\DteCanaryTargetData;
use App\Http\Requests\AuthenticatedRequest;

final class SelectDteCanaryTargetRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'integer', 'min:1'],
            'client_id' => ['required', 'integer', 'min:1'],
            'operation_key' => ['prohibited'],
            'id_sistema' => ['prohibited'],
            'id_servico' => ['prohibited'],
            'functional_route' => ['prohibited'],
            'business_data' => ['prohibited'],
            'payload' => ['prohibited'],
        ];
    }

    public function toDto(): DteCanaryTargetData
    {
        return new DteCanaryTargetData(
            tenantId: (int) $this->validated('tenant_id'),
            clientId: (int) $this->validated('client_id'),
        );
    }
}

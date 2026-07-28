<?php

namespace App\Http\Requests\Sefaz;

use App\Http\Requests\AuthenticatedRequest;

/** Admin + senha recente revalidada no controller. */
final class RevokeCteEmitterTokenRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
        ];
    }
}

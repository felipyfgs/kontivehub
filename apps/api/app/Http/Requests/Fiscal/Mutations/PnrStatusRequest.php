<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Http\Requests\AuthenticatedRequest;

final class PnrStatusRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'id_solicitacao' => ['required', 'string', 'max:120'],
        ];
    }

    public function solicitationId(): string
    {
        return (string) $this->validated('id_solicitacao');
    }
}

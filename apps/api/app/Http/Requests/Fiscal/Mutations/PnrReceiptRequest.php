<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Http\Requests\AuthenticatedRequest;

final class PnrReceiptRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'renunciation_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function renunciationId(): int
    {
        return (int) $this->validated('renunciation_id');
    }
}

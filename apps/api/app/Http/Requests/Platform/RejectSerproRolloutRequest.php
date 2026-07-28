<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\SerproRolloutRejectionData;
use App\Http\Requests\AuthenticatedRequest;

final class RejectSerproRolloutRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function toDto(): SerproRolloutRejectionData
    {
        return new SerproRolloutRejectionData(
            reason: $this->validated('reason'),
        );
    }
}

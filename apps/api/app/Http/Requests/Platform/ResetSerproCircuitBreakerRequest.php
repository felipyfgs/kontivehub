<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\SerproCircuitBreakerResetData;
use App\Http\Requests\AuthenticatedRequest;

final class ResetSerproCircuitBreakerRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function toDto(): SerproCircuitBreakerResetData
    {
        return new SerproCircuitBreakerResetData(
            reason: (string) $this->validated('reason'),
        );
    }
}

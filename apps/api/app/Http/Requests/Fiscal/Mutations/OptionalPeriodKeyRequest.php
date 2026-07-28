<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Http\Requests\AuthenticatedRequest;

final class OptionalPeriodKeyRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'period_key' => ['sometimes', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ];
    }

    public function periodKey(): ?string
    {
        $value = $this->validated('period_key');

        return is_string($value) ? $value : null;
    }
}

<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\DteCanaryReconciliationData;
use App\Http\Requests\AuthenticatedRequest;

final class ReconcileDteCanaryRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'reference' => ['required', 'string', 'max:200'],
            'summary' => ['required', 'string', 'max:1000'],
        ];
    }

    public function toDto(): DteCanaryReconciliationData
    {
        return new DteCanaryReconciliationData(
            reference: (string) $this->validated('reference'),
            summary: (string) $this->validated('summary'),
        );
    }
}

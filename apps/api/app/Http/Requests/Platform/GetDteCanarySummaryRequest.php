<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\DteCanarySummaryFilterData;
use App\Http\Requests\AuthenticatedRequest;

final class GetDteCanarySummaryRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge($this->query->all());
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'request_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function toDto(): DteCanarySummaryFilterData
    {
        $requestId = $this->validated('request_id');

        return new DteCanarySummaryFilterData(
            requestId: is_numeric($requestId) ? (int) $requestId : null,
        );
    }
}

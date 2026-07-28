<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\UsagePeriodData;
use App\Http\Requests\AuthenticatedRequest;

final class GetSerproUsageConsolidationRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge($this->query->all());
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'year' => ['sometimes', 'integer', 'min:2020', 'max:2100'],
            'month' => ['sometimes', 'integer', 'min:1', 'max:12'],
        ];
    }

    public function toDto(): UsagePeriodData
    {
        $year = $this->validated('year');
        $month = $this->validated('month');

        return new UsagePeriodData(
            year: is_numeric($year) ? (int) $year : null,
            month: is_numeric($month) ? (int) $month : null,
        );
    }
}

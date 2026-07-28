<?php

namespace App\Http\Requests\Fiscal\Mutations;

final class ConsultDefisLatestRequest extends ConfirmFiscalOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
            'calendar_year' => ['required', 'integer', 'between:2000,2100'],
        ];
    }

    public function calendarYear(): int
    {
        return (int) $this->validated('calendar_year');
    }
}

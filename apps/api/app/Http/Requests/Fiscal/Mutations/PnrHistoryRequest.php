<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Http\Requests\AuthenticatedRequest;

final class PnrHistoryRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'dt_inicio' => ['nullable', 'date_format:Y-m-d'],
            'dt_fim' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:0'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->validated();
    }
}

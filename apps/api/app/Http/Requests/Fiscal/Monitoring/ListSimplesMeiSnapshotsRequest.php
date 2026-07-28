<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\SimplesMeiSnapshotFilters;
use Illuminate\Validation\Rule;

final class ListSimplesMeiSnapshotsRequest extends ViewSimplesMeiClientRequest
{
    protected function prepareSimplesMeiValidation(): void
    {
        $system = $this->input('system_code');
        if (is_string($system)) {
            $this->merge(['system_code' => strtoupper(trim($system))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'system_code' => [
                'sometimes',
                'string',
                Rule::in(['INTEGRA_SN', 'INTEGRA_MEI']),
            ],
        ];
    }

    public function filters(): SimplesMeiSnapshotFilters
    {
        $data = $this->validated();

        return new SimplesMeiSnapshotFilters(
            perPage: (int) ($data['per_page'] ?? 50),
            systemCode: $data['system_code'] ?? null,
        );
    }
}

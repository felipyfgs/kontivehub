<?php

namespace App\Http\Requests\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalRunFilters;

final class ListFgtsDigitalRunsRequest extends ViewFgtsDigitalRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['sometimes', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filters(): FgtsDigitalRunFilters
    {
        $validated = $this->validated();

        return new FgtsDigitalRunFilters(
            clientId: isset($validated['client_id'])
                ? (int) $validated['client_id']
                : null,
            perPage: (int) ($validated['per_page'] ?? 50),
        );
    }
}

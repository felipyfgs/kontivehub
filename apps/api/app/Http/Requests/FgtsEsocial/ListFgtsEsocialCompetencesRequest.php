<?php

namespace App\Http\Requests\FgtsEsocial;

use App\DTO\Esocial\FgtsEsocialListFilters;

final class ListFgtsEsocialCompetencesRequest extends ViewFgtsEsocialRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'client_id' => ['sometimes', 'nullable', 'integer'],
            'competence_period_key' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ];
    }

    public function filters(): FgtsEsocialListFilters
    {
        $validated = $this->validated();

        return new FgtsEsocialListFilters(
            perPage: (int) ($validated['per_page'] ?? 50),
            clientId: isset($validated['client_id']) ? (int) $validated['client_id'] : null,
            competencePeriodKey: $validated['competence_period_key'] ?? null,
        );
    }
}

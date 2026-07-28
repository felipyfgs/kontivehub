<?php

namespace App\Http\Requests\Outbound;

use App\Domain\Outbound\Competence;
use App\DTO\Outbound\OutboundMonthlyExportData;
use App\Rules\ValidOutboundCompetence;

final class ExportOutboundMonthlyRequest extends OperateOutboundRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'competence' => ['required', 'string', new ValidOutboundCompetence],
            'include_events' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function exportData(): OutboundMonthlyExportData
    {
        $validated = $this->validated();

        return new OutboundMonthlyExportData(
            competence: Competence::fromString(
                (string) $validated['competence'],
            ),
            includeEvents: (bool) ($validated['include_events'] ?? false),
            notes: isset($validated['notes'])
                ? (string) $validated['notes']
                : null,
        );
    }
}

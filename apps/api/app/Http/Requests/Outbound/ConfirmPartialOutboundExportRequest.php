<?php

namespace App\Http\Requests\Outbound;

use App\Domain\Outbound\Competence;
use App\DTO\Outbound\OutboundPartialConfirmationData;
use App\Rules\ValidOutboundCompetence;

final class ConfirmPartialOutboundExportRequest extends OperateOutboundRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'competence' => ['required', 'string', new ValidOutboundCompetence],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function confirmationData(): OutboundPartialConfirmationData
    {
        $validated = $this->validated();

        return new OutboundPartialConfirmationData(
            competence: Competence::fromString(
                (string) $validated['competence'],
            ),
            notes: isset($validated['notes'])
                ? (string) $validated['notes']
                : null,
        );
    }
}

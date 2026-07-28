<?php

namespace App\Http\Requests\Outbound;

use App\Domain\Outbound\Competence;
use App\DTO\Outbound\OutboundCompetenceFilter;
use App\Rules\ValidOutboundCompetence;

final class InspectOutboundCompetenceRequest extends ViewOutboundRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'competence' => ['nullable', 'string', new ValidOutboundCompetence],
        ];
    }

    public function competenceFilter(): OutboundCompetenceFilter
    {
        $competence = $this->validated('competence');

        return new OutboundCompetenceFilter(
            competence: is_string($competence)
                ? Competence::fromString($competence)
                : null,
        );
    }
}

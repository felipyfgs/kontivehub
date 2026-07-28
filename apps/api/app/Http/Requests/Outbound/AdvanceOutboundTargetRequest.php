<?php

namespace App\Http\Requests\Outbound;

use App\Domain\Outbound\Competence;
use App\DTO\Outbound\OutboundTargetAdvanceData;
use App\Rules\ValidOutboundCompetence;
use Carbon\CarbonImmutable;

final class AdvanceOutboundTargetRequest extends AdministerOutboundRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'competence' => ['required', 'string', new ValidOutboundCompetence],
            'target_at' => ['required', 'date'],
        ];
    }

    public function targetData(): OutboundTargetAdvanceData
    {
        $validated = $this->validated();

        return new OutboundTargetAdvanceData(
            competence: Competence::fromString(
                (string) $validated['competence'],
            ),
            targetAt: CarbonImmutable::parse(
                (string) $validated['target_at'],
            )->utc(),
        );
    }
}

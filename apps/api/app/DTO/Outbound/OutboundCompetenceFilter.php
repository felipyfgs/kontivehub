<?php

namespace App\DTO\Outbound;

use App\Domain\Outbound\Competence;

final readonly class OutboundCompetenceFilter
{
    public function __construct(
        public ?Competence $competence,
    ) {}

    public function valueOrCurrent(): string
    {
        return $this->competence?->value() ?? now()->format('Y-m');
    }

    public function value(): ?string
    {
        return $this->competence?->value();
    }
}

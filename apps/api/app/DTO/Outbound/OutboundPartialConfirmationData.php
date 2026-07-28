<?php

namespace App\DTO\Outbound;

use App\Domain\Outbound\Competence;

final readonly class OutboundPartialConfirmationData
{
    public function __construct(
        public Competence $competence,
        public ?string $notes,
    ) {}
}

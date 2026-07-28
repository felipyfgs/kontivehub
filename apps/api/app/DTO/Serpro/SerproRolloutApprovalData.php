<?php

namespace App\DTO\Serpro;

use Carbon\CarbonImmutable;

final readonly class SerproRolloutApprovalData
{
    public function __construct(
        public ?string $reason,
        public ?string $confirmationPhrase,
        public ?CarbonImmutable $changeWindowStart,
        public ?CarbonImmutable $changeWindowEnd,
    ) {}
}

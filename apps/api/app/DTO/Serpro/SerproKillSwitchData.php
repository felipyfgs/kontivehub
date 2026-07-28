<?php

namespace App\DTO\Serpro;

use Carbon\CarbonImmutable;

final readonly class SerproKillSwitchData
{
    public function __construct(
        public bool $active,
        public string $reason,
        public ?string $solution,
        public ?string $confirmationPhrase,
        public ?CarbonImmutable $changeWindowStart,
        public ?CarbonImmutable $changeWindowEnd,
    ) {}

    public function isSolutionScoped(): bool
    {
        return $this->solution !== null;
    }
}

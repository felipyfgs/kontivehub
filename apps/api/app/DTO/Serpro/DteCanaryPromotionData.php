<?php

namespace App\DTO\Serpro;

use Carbon\CarbonImmutable;

final readonly class DteCanaryPromotionData
{
    public function __construct(
        public string $confirmationPhrase,
        public string $reason,
        public ?CarbonImmutable $changeWindowStart,
        public ?CarbonImmutable $changeWindowEnd,
        public int $maxQuantity,
    ) {}
}

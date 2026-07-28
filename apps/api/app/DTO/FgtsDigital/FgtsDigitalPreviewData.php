<?php

namespace App\DTO\FgtsDigital;

use App\Enums\FgtsDigitalGuideType;

final readonly class FgtsDigitalPreviewData
{
    /** @param array<string, mixed> $parameters */
    public function __construct(
        public int $clientId,
        public FgtsDigitalGuideType $guideType,
        public array $parameters,
    ) {}
}

<?php

namespace App\DTO\FgtsDigital;

final readonly class FgtsDigitalEmissionData
{
    public function __construct(
        public int $previewRunId,
        public string $previewToken,
        public string $confirmationPhrase,
    ) {}
}

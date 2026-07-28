<?php

namespace App\DTO\MeiAutomation;

final readonly class MeiAutomationArtifactData
{
    public function __construct(
        public string $bytes,
        public string $name,
        public string $contentType,
    ) {}
}

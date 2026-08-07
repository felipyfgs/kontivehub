<?php

namespace App\DTO\Communication;

final readonly class ValidatedOutboundMediaData
{
    public function __construct(
        public string $mime,
        public int $sizeBytes,
        public string $sha256,
    ) {}
}

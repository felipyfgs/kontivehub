<?php

namespace App\Domain\Adn;

use App\Enums\AdnDocumentType;

final readonly class DistributionDocumentDto
{
    public function __construct(
        public int $nsu,
        public AdnDocumentType $type,
        public string $schema,
        public string $contentBase64,
        public ?string $accessKey = null,
    ) {}
}

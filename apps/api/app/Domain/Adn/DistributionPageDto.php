<?php

namespace App\Domain\Adn;

final readonly class DistributionPageDto
{
    /**
     * @param  list<DistributionDocumentDto>  $documents
     */
    public function __construct(
        public string $status,
        public int $maxNsu,
        public int $ultimoNsu,
        public array $documents,
        public bool $hasMore,
        public ?string $rawXml = null,
    ) {}
}

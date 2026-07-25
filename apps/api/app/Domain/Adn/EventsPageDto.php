<?php

namespace App\Domain\Adn;

final readonly class EventsPageDto
{
    /**
     * @param  list<DistributionDocumentDto>  $events
     */
    public function __construct(
        public string $accessKey,
        public array $events,
        public ?string $rawXml = null,
    ) {}
}

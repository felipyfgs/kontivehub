<?php

namespace App\DTO\Esocial;

final readonly class FgtsEsocialEventFilters
{
    public function __construct(
        public int $perPage,
        public ?int $clientId,
        public ?string $competencePeriodKey,
        public ?string $eventCode,
    ) {}
}

<?php

namespace App\DTO\Esocial;

final readonly class FgtsEsocialListFilters
{
    public function __construct(
        public int $perPage,
        public ?int $clientId,
        public ?string $competencePeriodKey,
    ) {}
}

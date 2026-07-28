<?php

namespace App\DTO\Clients;

final readonly class ClientCategoryCreationData
{
    public function __construct(
        public string $name,
        public string $nameKey,
        public string $color,
        public int $actorId,
    ) {}
}

<?php

namespace App\DTO\Clients;

use App\Models\Client;

final readonly class ClientCategoryReplacementResult
{
    public function __construct(
        public Client $client,
        public int $added,
        public int $removed,
    ) {}
}

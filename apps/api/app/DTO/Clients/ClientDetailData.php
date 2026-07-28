<?php

namespace App\DTO\Clients;

use App\Models\Client;

final readonly class ClientDetailData
{
    /**
     * @param  array<int, array<string, mixed>>  $captureEligibility
     * @param  array{status: string, valid_to: ?string, checked_at: ?string}  $procuracaoProjection
     */
    public function __construct(
        public Client $client,
        public array $captureEligibility,
        public array $procuracaoProjection,
    ) {}
}

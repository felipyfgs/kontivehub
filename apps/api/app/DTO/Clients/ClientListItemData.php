<?php

namespace App\DTO\Clients;

use App\Models\Client;

final readonly class ClientListItemData
{
    /**
     * @param  array{status: string, valid_to: ?string, checked_at: ?string}  $procuracaoProjection
     * @param  array{enabled: bool, status: string, establishments_total: int, establishments_enabled: int}  $captureSummary
     * @param  array{status: string, last_success_at: ?string, has_cursor: bool}  $syncSummary
     */
    public function __construct(
        public Client $client,
        public array $procuracaoProjection,
        public array $captureSummary,
        public array $syncSummary,
    ) {}
}

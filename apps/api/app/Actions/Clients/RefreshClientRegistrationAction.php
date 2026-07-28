<?php

namespace App\Actions\Clients;

use App\DTO\Clients\ClientRegistrationRefreshData;
use App\DTO\Clients\ClientRegistrationRefreshResult;
use App\Models\Client;
use App\Services\Clients\RefreshClientRegistration;

final readonly class RefreshClientRegistrationAction
{
    public function __construct(
        private RefreshClientRegistration $registration,
    ) {}

    public function __invoke(
        Client $client,
        ClientRegistrationRefreshData $data,
    ): ClientRegistrationRefreshResult {
        $result = $this->registration->handle($client, $data->lookup);
        $fresh = $result['client']->load([
            'establishments' => fn ($query) => $query
                ->orderByDesc('is_headquarters')
                ->orderBy('id'),
            'contacts',
            'categories',
        ]);

        return new ClientRegistrationRefreshResult(
            client: $fresh,
            lookup: $result['lookup'],
        );
    }
}

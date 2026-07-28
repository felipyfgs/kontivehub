<?php

namespace App\Actions\Clients;

use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Support\Collection;

final readonly class ListClientContactsAction
{
    /** @return Collection<int, ClientContact> */
    public function __invoke(Client $client): Collection
    {
        return $client->contacts()
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get();
    }
}

<?php

namespace App\Actions\Clients;

use App\DTO\Clients\ClientUpdateData;
use App\Models\Client;
use App\Services\Audit\AuditLogger;

final readonly class UpdateClientAction
{
    public function __construct(
        private AuditLogger $audit,
    ) {}

    public function __invoke(Client $client, ClientUpdateData $data): Client
    {
        $client->fill($data->attributes);
        $changed = array_keys($client->getDirty());
        $client->save();

        $this->audit->record('client.update', 'SUCCESS', $client, [
            'fields' => $changed,
        ]);

        return $client->fresh()?->load([
            'categories' => fn ($query) => $query->orderBy('name')->orderBy('id'),
            'workDepartment',
        ]) ?? $client;
    }
}

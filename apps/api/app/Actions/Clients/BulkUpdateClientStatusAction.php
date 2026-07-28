<?php

namespace App\Actions\Clients;

use App\DTO\Clients\BulkClientStatusData;
use App\DTO\Clients\BulkClientStatusResult;
use App\Models\Client;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class BulkUpdateClientStatusAction
{
    public function __construct(
        private Gate $gate,
        private AuditLogger $audit,
    ) {}

    public function __invoke(BulkClientStatusData $data, User $actor): BulkClientStatusResult
    {
        /** @var Collection<int, Client> $clients */
        $clients = DB::transaction(function () use ($data, $actor): Collection {
            /** @var Collection<int, Client> $clients */
            $clients = Client::query()
                ->whereIn('id', $data->clientIds)
                ->lockForUpdate()
                ->get()
                ->keyBy(fn (Client $client): int => (int) $client->id);

            if ($clients->count() !== count($data->clientIds)) {
                throw ValidationException::withMessages([
                    'client_ids' => ['Um ou mais clientes não pertencem ao escritório atual ou não estão disponíveis.'],
                ]);
            }

            foreach ($data->clientIds as $clientId) {
                /** @var Client $client */
                $client = $clients->get($clientId);
                $this->gate->forUser($actor)->authorize('update', $client);
                $client->forceFill([
                    'is_active' => $data->isActive,
                    'inactive_reason' => $data->inactiveReason,
                ])->save();
            }

            return $clients->values();
        });

        foreach ($clients as $client) {
            $this->audit->record('client.bulk_status_update', 'SUCCESS', $client, [
                'is_active' => $data->isActive,
                'batch_size' => count($data->clientIds),
            ]);
        }

        return new BulkClientStatusResult(
            updated: $clients->count(),
            clientIds: $data->clientIds,
            isActive: $data->isActive,
        );
    }
}

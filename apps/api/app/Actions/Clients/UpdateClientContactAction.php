<?php

namespace App\Actions\Clients;

use App\DTO\Clients\ClientContactUpdateData;
use App\Models\Client;
use App\Models\ClientContact;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class UpdateClientContactAction
{
    public function __construct(
        private AuditLogger $audit,
    ) {}

    public function __invoke(
        Client $client,
        ClientContact $contact,
        ClientContactUpdateData $data,
    ): ClientContact {
        $this->assertBelongsToClient($client, $contact);

        $updated = DB::transaction(function () use ($client, $contact, $data): ClientContact {
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
            $locked = ClientContact::query()
                ->whereKey($contact->id)
                ->lockForUpdate()
                ->firstOrFail();

            $willBePrimary = array_key_exists('is_primary', $data->attributes)
                ? (bool) $data->attributes['is_primary']
                : (bool) $locked->is_primary;
            $willBeActive = array_key_exists('is_active', $data->attributes)
                ? (bool) $data->attributes['is_active']
                : (bool) $locked->is_active;

            $this->assertPrimaryIsActive($willBePrimary, $willBeActive);

            if ($willBePrimary && ! $locked->is_primary) {
                ClientContact::query()
                    ->where('client_id', $client->id)
                    ->where('id', '!=', $locked->id)
                    ->where('is_primary', true)
                    ->where('is_active', true)
                    ->update(['is_primary' => false]);
            }

            $locked->fill($data->attributes);
            $locked->save();

            return $locked->fresh() ?? $locked;
        });

        $this->audit->record('client_contact.update', 'SUCCESS', $updated, [
            'client_id' => $client->id,
            'fields' => array_keys($data->attributes),
        ]);

        return $updated;
    }

    private function assertBelongsToClient(Client $client, ClientContact $contact): void
    {
        if ((int) $contact->client_id !== (int) $client->id) {
            throw (new ModelNotFoundException)->setModel(
                ClientContact::class,
                [$contact->id],
            );
        }
    }

    private function assertPrimaryIsActive(bool $isPrimary, bool $isActive): void
    {
        if ($isPrimary && ! $isActive) {
            throw ValidationException::withMessages([
                'is_primary' => ['Contato principal precisa estar ativo.'],
                'is_active' => ['Não é possível marcar como principal um contato inativo.'],
            ]);
        }
    }
}

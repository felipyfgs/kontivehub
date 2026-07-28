<?php

namespace App\Actions\Clients;

use App\DTO\Clients\ClientContactCreationData;
use App\Models\Client;
use App\Models\ClientContact;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CreateClientContactAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuditLogger $audit,
    ) {}

    public function __invoke(Client $client, ClientContactCreationData $data): ClientContact
    {
        $attributes = $data->attributes;
        $isPrimary = (bool) ($attributes['is_primary'] ?? false);
        $isActive = array_key_exists('is_active', $attributes)
            ? (bool) $attributes['is_active']
            : true;

        $this->assertPrimaryIsActive($isPrimary, $isActive);

        $contact = DB::transaction(function () use (
            $client,
            $attributes,
            $isPrimary,
            $isActive,
        ): ClientContact {
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();

            if ($isPrimary) {
                ClientContact::query()
                    ->where('client_id', $client->id)
                    ->where('is_primary', true)
                    ->where('is_active', true)
                    ->update(['is_primary' => false]);
            }

            return ClientContact::query()->create([
                'tenant_id' => $this->currentTenant->id(),
                'client_id' => $client->id,
                'name' => $attributes['name'],
                'role' => $attributes['role'] ?? null,
                'email' => $attributes['email'] ?? null,
                'phone' => $attributes['phone'] ?? null,
                'is_whatsapp' => $attributes['is_whatsapp'] ?? false,
                'is_primary' => $isPrimary,
                'receives_alerts' => $attributes['receives_alerts'] ?? false,
                'notes' => $attributes['notes'] ?? null,
                'is_active' => $isActive,
            ]);
        });

        $this->audit->record('client_contact.create', 'SUCCESS', $contact, [
            'client_id' => $client->id,
            'fields' => ['name', 'is_primary'],
            'is_primary' => $contact->is_primary,
        ]);

        return $contact;
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

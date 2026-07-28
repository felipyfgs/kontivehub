<?php

namespace App\Actions\Clients;

use App\Models\Client;
use App\Models\ClientContact;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class DeleteClientContactAction
{
    public function __construct(
        private AuditLogger $audit,
    ) {}

    public function __invoke(Client $client, ClientContact $contact): void
    {
        if ((int) $contact->client_id !== (int) $client->id) {
            throw (new ModelNotFoundException)->setModel(
                ClientContact::class,
                [$contact->id],
            );
        }

        $contact->delete();

        $this->audit->record('client_contact.delete', 'SUCCESS', $contact, [
            'client_id' => $client->id,
        ]);
    }
}

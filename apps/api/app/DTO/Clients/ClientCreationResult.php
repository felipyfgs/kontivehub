<?php

namespace App\DTO\Clients;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientCustomField;
use App\Models\Establishment;

final readonly class ClientCreationResult
{
    /** @param list<ClientCustomField> $customFields */
    public function __construct(
        public Client $client,
        public Establishment $establishment,
        public ?ClientContact $contact,
        public array $customFields,
    ) {}
}

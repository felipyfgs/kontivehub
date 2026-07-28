<?php

namespace App\DTO\Communication;

use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\CommunicationIdentity;
use Illuminate\Support\Collection;

final readonly class CommunicationRecipientConfigurationData
{
    /**
     * @param  Collection<int, int>  $selectedIdentityIds
     * @param  Collection<int, CommunicationIdentity>  $identities
     */
    public function __construct(
        public Client $client,
        public ?ClientCommunicationPreference $preference,
        public Collection $selectedIdentityIds,
        public Collection $identities,
    ) {}
}

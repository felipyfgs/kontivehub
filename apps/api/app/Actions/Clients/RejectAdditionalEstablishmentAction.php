<?php

namespace App\Actions\Clients;

use App\Exceptions\EstablishmentApiException;
use App\Models\Client;

final readonly class RejectAdditionalEstablishmentAction
{
    public function __invoke(Client $client): never
    {
        throw EstablishmentApiException::additionalEstablishmentNotSupported();
    }
}

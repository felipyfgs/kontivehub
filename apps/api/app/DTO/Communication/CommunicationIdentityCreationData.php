<?php

namespace App\DTO\Communication;

final readonly class CommunicationIdentityCreationData
{
    public function __construct(public string $phone) {}
}

<?php

namespace App\Contracts;

use App\DTO\Communication\GatewayCommandData;

interface CommunicationCommandPublisher
{
    public function publish(GatewayCommandData $command): void;
}

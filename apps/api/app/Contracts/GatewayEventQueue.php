<?php

namespace App\Contracts;

use App\DTO\Communication\GatewayEventDelivery;

interface GatewayEventQueue
{
    public function next(float $timeoutSeconds = 1.0): ?GatewayEventDelivery;
}

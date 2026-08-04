<?php

namespace App\DTO\Communication;

use Closure;

final readonly class GatewayEventDelivery
{
    public function __construct(
        public string $body,
        private Closure $acknowledge,
        private Closure $retry,
        private Closure $terminate,
    ) {}

    public function ack(): void
    {
        ($this->acknowledge)();
    }

    public function nack(float $delaySeconds = 1.0): void
    {
        ($this->retry)($delaySeconds);
    }

    public function term(): void
    {
        ($this->terminate)();
    }
}

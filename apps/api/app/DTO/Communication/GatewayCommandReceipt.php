<?php

namespace App\DTO\Communication;

final readonly class GatewayCommandReceipt
{
    public function __construct(
        public string $commandId,
        public bool $duplicate,
    ) {}
}

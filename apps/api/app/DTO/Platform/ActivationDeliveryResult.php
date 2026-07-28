<?php

namespace App\DTO\Platform;

final readonly class ActivationDeliveryResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
        public int $httpStatus = 200,
    ) {}
}

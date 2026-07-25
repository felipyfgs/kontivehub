<?php

namespace App\DTO\Outbound;

final readonly class MaRetrievalPollResult
{
    public function __construct(
        public string $status,
        public ?string $externalRef = null,
        public ?string $message = null,
        public bool $ready = false,
        public bool $expired = false,
    ) {}
}

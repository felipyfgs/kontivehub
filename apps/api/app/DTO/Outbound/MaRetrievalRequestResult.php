<?php

namespace App\DTO\Outbound;

final readonly class MaRetrievalRequestResult
{
    public function __construct(
        public bool $accepted,
        public ?string $externalRef = null,
        public ?string $status = null,
        public ?string $message = null,
    ) {}
}

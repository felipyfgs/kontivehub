<?php

namespace App\DTO\Mailbox;

final readonly class CaixaPostalIndicatorResult
{
    /** @param array<string, mixed> $meta */
    public function __construct(
        public bool $success,
        public ?int $indicator = null,
        public bool $simulated = false,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $meta = [],
        public string $sourceVersion = '1.0',
    ) {}
}

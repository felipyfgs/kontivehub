<?php

namespace App\DTO\Mailbox;

use App\Enums\MailboxDteStatus;

final readonly class DteIndicatorResult
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public bool $success,
        public MailboxDteStatus $status,
        public bool $simulated = false,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $meta = [],
        public string $sourceVersion = '1.0',
    ) {}
}

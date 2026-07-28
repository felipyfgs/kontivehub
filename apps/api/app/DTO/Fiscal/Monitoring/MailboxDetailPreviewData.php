<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class MailboxDetailPreviewData
{
    /** @param array<string, mixed> $cost */
    public function __construct(
        public bool $hasBody,
        public array $cost,
    ) {}
}

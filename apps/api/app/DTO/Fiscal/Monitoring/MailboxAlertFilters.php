<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class MailboxAlertFilters
{
    public function __construct(
        public int $perPage,
        public bool $activeOnly,
    ) {}
}

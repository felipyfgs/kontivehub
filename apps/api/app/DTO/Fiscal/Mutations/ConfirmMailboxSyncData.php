<?php

namespace App\DTO\Fiscal\Mutations;

final readonly class ConfirmMailboxSyncData
{
    public function __construct(
        public bool $forceAll,
        public string $idempotencyKey,
    ) {}
}

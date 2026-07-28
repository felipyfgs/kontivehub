<?php

namespace App\DTO\Fiscal;

final readonly class FiscalModuleRestrictionData
{
    public function __construct(
        public bool $restricted,
        public string $reason,
    ) {}
}

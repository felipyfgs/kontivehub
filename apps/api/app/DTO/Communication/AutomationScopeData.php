<?php

namespace App\DTO\Communication;

final readonly class AutomationScopeData
{
    public function __construct(
        public string $moduleKey,
        public string $submoduleKey,
    ) {}
}

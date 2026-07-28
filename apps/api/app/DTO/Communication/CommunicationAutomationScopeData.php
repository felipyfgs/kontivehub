<?php

namespace App\DTO\Communication;

final readonly class CommunicationAutomationScopeData
{
    public function __construct(
        public string $moduleKey,
        public string $submoduleKey,
    ) {}
}

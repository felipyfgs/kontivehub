<?php

namespace App\Actions\Tenant;

use App\Enums\SerproEnvironment;
use App\Services\Integra\TenantIntegraHealthService;

final readonly class ShowTenantSerproHealthAction
{
    public function __construct(
        private TenantIntegraHealthService $health,
    ) {}

    /** @return array<string, mixed> */
    public function __invoke(SerproEnvironment $environment): array
    {
        return $this->health->forEnvironment($environment);
    }
}

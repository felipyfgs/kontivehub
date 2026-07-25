<?php

namespace App\Services\Integra;

use App\Enums\SerproEnvironment;
use App\Services\Serpro\SerproHealthService;

/**
 * Fachada tenant-safe para saúde sanitizada da integração.
 * Controllers tenant usam esta classe (não SerproHealthService direto).
 */
final class TenantIntegraHealthService
{
    public function __construct(
        private readonly SerproHealthService $health,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forEnvironment(SerproEnvironment $environment): array
    {
        return $this->health->tenantHealth($environment);
    }
}

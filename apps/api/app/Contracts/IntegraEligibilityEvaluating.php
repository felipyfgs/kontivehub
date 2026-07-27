<?php

namespace App\Contracts;

use App\DTO\Serpro\EligibilityResult;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;

/** Pré-call Integra Contador (fail-closed). */
interface IntegraEligibilityEvaluating
{
    public function evaluate(
        Tenant $tenant,
        Client $client,
        string $solutionCode,
        string $serviceCode,
        string $operationCode,
        SerproEnvironment $environment,
        ?User $user = null,
        ?string $module = null,
        bool $requireD1 = false,
        bool $freeSmokeMode = false,
    ): EligibilityResult;

    public function touchRateLimit(int $tenantId): void;
}

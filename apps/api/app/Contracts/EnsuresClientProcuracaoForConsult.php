<?php

namespace App\Contracts;

use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\Tenant;

/**
 * Garante evidência de procuração usável antes de consulta Integra que exige poder e-CAC.
 *
 * @phpstan-type EnsureResult array{ok: bool, synced: bool, code: ?string, message: ?string}
 */
interface EnsuresClientProcuracaoForConsult
{
    /**
     * @param  list<string>  $requiredPowers
     * @return EnsureResult
     */
    public function ensure(
        Tenant $tenant,
        Client $client,
        SerproEnvironment $environment,
        array $requiredPowers,
        ?int $actorUserId = null,
    ): array;
}

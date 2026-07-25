<?php

namespace App\Contracts;

/**
 * Sonda/autorização separada do cliente read-only.
 * Implementação de produção permanece inativa até G5.
 */
interface SefazOutboundMutatingProbeClient
{
    public function isActive(): bool;

    /**
     * @param  array{pfx: string, password: string}  $certificate
     * @param  array<string, mixed>  $payload  Template de homologação versionado
     * @return array{cStat: string, xMotivo: string, access_key: ?string, authorized: bool, raw?: string}
     */
    public function probe(
        string $model,
        string $environment,
        array $payload,
        array $certificate,
    ): array;
}

<?php

namespace App\Contracts;

/**
 * Cliente de inutilização — produção inativa por default; fakes no CI.
 */
interface SefazOutboundInutilizationClient
{
    /**
     * @param  array{pfx: string, password: string}  $certificate
     * @return array{cStat: string, xMotivo: string, outcome: string, protocol: ?string, raw?: string}
     */
    public function inutilize(
        string $model,
        string $environment,
        int $series,
        int $nnfIni,
        int $nnfFin,
        string $year,
        string $cnpj,
        array $certificate,
    ): array;
}

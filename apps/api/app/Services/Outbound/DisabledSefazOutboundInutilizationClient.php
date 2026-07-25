<?php

namespace App\Services\Outbound;

use App\Contracts\SefazOutboundInutilizationClient;

final class DisabledSefazOutboundInutilizationClient implements SefazOutboundInutilizationClient
{
    public function inutilize(
        string $model,
        string $environment,
        int $series,
        int $nnfIni,
        int $nnfFin,
        string $year,
        string $cnpj,
        array $certificate,
    ): array {
        return [
            'cStat' => '000',
            'xMotivo' => 'Cliente de inutilização desabilitado (G5).',
            'outcome' => 'AMBIGUOUS',
            'protocol' => null,
        ];
    }
}

<?php

namespace App\Services\Outbound;

use App\Contracts\SefazOutboundMutatingProbeClient;

final class DisabledSefazOutboundMutatingProbeClient implements SefazOutboundMutatingProbeClient
{
    public function isActive(): bool
    {
        return false;
    }

    public function probe(
        string $model,
        string $environment,
        array $payload,
        array $certificate,
    ): array {
        return [
            'cStat' => '000',
            'xMotivo' => 'Cliente de sonda mutante desabilitado (G5).',
            'access_key' => null,
            'authorized' => false,
        ];
    }
}

<?php

namespace App\Services\Outbound;

use App\Contracts\MaOutboundXmlRetrievalClient;
use App\DTO\Outbound\MaRetrievalDownloadResult;
use App\DTO\Outbound\MaRetrievalPollResult;
use App\DTO\Outbound\MaRetrievalRequestResult;

/**
 * Implementação default enquanto G4 = NO_GO_M2M.
 * Ingestão assistida de pacote oficial não passa por este cliente.
 */
final class DisabledMaOutboundXmlRetrievalClient implements MaOutboundXmlRetrievalClient
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function requestExport(
        string $competence,
        string $model,
        string $environment,
        array $certificate,
    ): MaRetrievalRequestResult {
        return new MaRetrievalRequestResult(
            accepted: false,
            status: 'NO_GO_M2M',
            message: 'Recuperação M2M desabilitada (sem contrato formal SEFAZ-MA). Use modo ASSISTED.',
        );
    }

    public function poll(string $externalRef, array $certificate): MaRetrievalPollResult
    {
        return new MaRetrievalPollResult(
            status: 'NO_GO_M2M',
            externalRef: $externalRef,
            message: 'Recuperação M2M desabilitada.',
        );
    }

    public function download(string $externalRef, array $certificate): MaRetrievalDownloadResult
    {
        return new MaRetrievalDownloadResult(
            success: false,
            message: 'Recuperação M2M desabilitada.',
        );
    }
}

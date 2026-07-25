<?php

namespace App\Contracts;

use App\DTO\Outbound\MaRetrievalDownloadResult;
use App\DTO\Outbound\MaRetrievalPollResult;
use App\DTO\Outbound\MaRetrievalRequestResult;

/**
 * Recuperação oficial de XML de saída MA.
 * Implementação M2M só após G4; default é Disabled/Null.
 */
interface MaOutboundXmlRetrievalClient
{
    public function isAvailable(): bool;

    public function requestExport(
        string $competence,
        string $model,
        string $environment,
        array $certificate,
    ): MaRetrievalRequestResult;

    public function poll(string $externalRef, array $certificate): MaRetrievalPollResult;

    public function download(string $externalRef, array $certificate): MaRetrievalDownloadResult;
}

<?php

namespace App\Services\Outbound;

use App\Contracts\SvrsNfceOutboundXmlRetrievalClient;
use App\DTO\Outbound\SvrsNfceRetrievalRequest;
use App\DTO\Outbound\SvrsNfceRetrievalResult;
use App\Enums\SvrsNfceTransportOutcome;

/**
 * Cliente disabled/fake para flag off, testes e ambientes sem canal.
 */
final class DisabledSvrsNfceOutboundXmlRetrievalClient implements SvrsNfceOutboundXmlRetrievalClient
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function retrieve(SvrsNfceRetrievalRequest $request, array $certificate): SvrsNfceRetrievalResult
    {
        // Nunca materializa HTML nem usa o certificado.
        unset($certificate);

        return new SvrsNfceRetrievalResult(
            outcome: SvrsNfceTransportOutcome::ChannelDisabled,
            sanitizedDetail: 'Canal SVRS NFC-e desabilitado.',
        );
    }
}

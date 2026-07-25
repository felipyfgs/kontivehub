<?php

namespace App\Services\Outbound;

use App\Contracts\SvrsNfe55OutboundXmlRetrievalClient;
use App\DTO\Outbound\SvrsNfceRetrievalRequest;
use App\DTO\Outbound\SvrsNfceRetrievalResult;
use App\Enums\SvrsNfceTransportOutcome;

final class DisabledSvrsNfe55OutboundXmlRetrievalClient implements SvrsNfe55OutboundXmlRetrievalClient
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function retrieve(SvrsNfceRetrievalRequest $request, array $certificate): SvrsNfceRetrievalResult
    {
        return new SvrsNfceRetrievalResult(
            outcome: SvrsNfceTransportOutcome::ChannelDisabled,
            sanitizedDetail: 'Canal SVRS NF-e 55 desabilitado.',
        );
    }
}

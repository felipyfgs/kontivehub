<?php

namespace App\Services\Outbound;

use App\Contracts\SvrsNfceOutboundXmlRetrievalClient;
use App\DTO\Outbound\SvrsNfceRetrievalRequest;
use App\DTO\Outbound\SvrsNfceRetrievalResult;
use App\Enums\SvrsNfceTransportOutcome;

/**
 * Fake configurável para testes de orquestração sem rede.
 */
final class FakeSvrsNfceOutboundXmlRetrievalClient implements SvrsNfceOutboundXmlRetrievalClient
{
    /** @var list<SvrsNfceRetrievalResult> */
    private array $queue = [];

    private bool $available = true;

    /** @var list<array{request: SvrsNfceRetrievalRequest, cert_keys: list<string>}> */
    public array $calls = [];

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function setAvailable(bool $available): void
    {
        $this->available = $available;
    }

    public function enqueue(SvrsNfceRetrievalResult $result): void
    {
        $this->queue[] = $result;
    }

    public function retrieve(SvrsNfceRetrievalRequest $request, array $certificate): SvrsNfceRetrievalResult
    {
        $this->calls[] = [
            'request' => $request,
            'cert_keys' => array_keys($certificate),
        ];

        if (! $this->available) {
            return new SvrsNfceRetrievalResult(
                outcome: SvrsNfceTransportOutcome::ChannelDisabled,
                sanitizedDetail: 'Fake client unavailable.',
            );
        }

        if ($this->queue === []) {
            return new SvrsNfceRetrievalResult(
                outcome: SvrsNfceTransportOutcome::RemoteNotFound,
                httpStatus: 200,
                sanitizedDetail: 'Fake queue empty.',
            );
        }

        return array_shift($this->queue);
    }
}

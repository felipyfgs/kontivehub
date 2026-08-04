<?php

namespace App\Services\Communication\Transport;

use App\Contracts\CommunicationCommandPublisher;
use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayCommandData;
use App\DTO\Communication\GatewayCommandReceipt;
use App\DTO\Communication\GatewayQueryData;
use App\Exceptions\CommunicationTransportException;
use Psr\Http\Message\StreamInterface;
use Throwable;

final readonly class JetStreamTransport implements CommunicationTransport
{
    public function __construct(
        private CommunicationCommandPublisher $publisher,
        private HttpTransport $http,
    ) {}

    public function dispatch(GatewayCommandData $command): GatewayCommandReceipt
    {
        if (! config('communication.enabled') || ! config('communication.gateway.enabled')) {
            throw new CommunicationTransportException('COMMUNICATION_DISABLED', false, 503);
        }

        try {
            $this->publisher->publish($command);
        } catch (Throwable) {
            throw new CommunicationTransportException('GATEWAY_QUEUE_UNAVAILABLE', true, null);
        }

        return new GatewayCommandReceipt($command->commandId, false);
    }

    public function query(GatewayQueryData $query): array
    {
        return $this->http->query($query);
    }

    public function sessionStatus(string $sessionId): array
    {
        return $this->http->sessionStatus($sessionId);
    }

    public function downloadMedia(string $spoolId): StreamInterface
    {
        return $this->http->downloadMedia($spoolId);
    }
}

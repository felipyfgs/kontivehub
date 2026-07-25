<?php

namespace App\DTO\Communication;

use App\Enums\Communication\GatewayEventType;
use DateTimeImmutable;
use InvalidArgumentException;

final readonly class GatewayEventData
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $gatewayEventId,
        public string $sessionId,
        public GatewayEventType $type,
        public DateTimeImmutable $occurredAt,
        public array $payload = [],
    ) {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $this->gatewayEventId)) {
            throw new InvalidArgumentException('gateway_event_id inválido.');
        }

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $this->sessionId)) {
            throw new InvalidArgumentException('session_id inválido.');
        }

        GatewayContractPayload::assertEvent($this->type, $this->payload);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract_version' => 'v1',
            'gateway_event_id' => $this->gatewayEventId,
            'session_id' => $this->sessionId,
            'type' => $this->type->value,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'payload' => GatewayContractPayload::jsonObject($this->payload),
        ];
    }
}

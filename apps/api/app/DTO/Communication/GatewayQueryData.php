<?php

namespace App\DTO\Communication;

use App\Enums\Communication\GatewayQueryType;
use InvalidArgumentException;

final readonly class GatewayQueryData
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $queryId,
        public string $sessionId,
        public GatewayQueryType $type,
        public array $payload = [],
    ) {
        self::assertIdentifier($this->queryId, 'query_id');
        self::assertIdentifier($this->sessionId, 'session_id');
        GatewayContractPayload::assertQuery($this->type, $this->payload);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'contract_version' => 'v1',
            'query_id' => $this->queryId,
            'session_id' => $this->sessionId,
            'type' => $this->type->value,
            'payload' => GatewayContractPayload::jsonObject($this->payload),
        ];
    }

    public function digest(): string
    {
        return CommunicationPayloadDigest::make($this->toArray());
    }

    private static function assertIdentifier(string $value, string $field): void
    {
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $value)) {
            throw new InvalidArgumentException("{$field} inválido.");
        }
    }
}

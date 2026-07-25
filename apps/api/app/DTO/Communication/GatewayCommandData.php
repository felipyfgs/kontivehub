<?php

namespace App\DTO\Communication;

use App\Enums\Communication\GatewayCommandType;
use InvalidArgumentException;

final readonly class GatewayCommandData
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $commandId,
        public string $sessionId,
        public GatewayCommandType $type,
        public array $payload = [],
        public ?string $providerMessageId = null,
    ) {
        self::assertIdentifier($this->commandId, 'command_id');
        self::assertIdentifier($this->sessionId, 'session_id');
        GatewayContractPayload::assertCommand($this->type, $this->payload);

        if ($this->providerMessageId === null && GatewayContractPayload::requiresProviderMessageId($this->type)) {
            throw new InvalidArgumentException("provider_message_id obrigatório para {$this->type->value}.");
        }

        if ($this->providerMessageId !== null) {
            self::assertIdentifier($this->providerMessageId, 'provider_message_id');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'contract_version' => 'v1',
            'command_id' => $this->commandId,
            'session_id' => $this->sessionId,
            'type' => $this->type->value,
            'provider_message_id' => $this->providerMessageId,
            'payload' => GatewayContractPayload::jsonObject($this->payload),
        ], static fn (mixed $value): bool => $value !== null);
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

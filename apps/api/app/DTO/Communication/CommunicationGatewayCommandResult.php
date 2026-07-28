<?php

namespace App\DTO\Communication;

use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\OutboxStatus;
use App\Models\CommunicationOutboxEntry;

final readonly class CommunicationGatewayCommandResult
{
    public function __construct(
        public string $commandId,
        public GatewayCommandType $type,
        public OutboxStatus $status,
    ) {}

    public static function fromEntry(CommunicationOutboxEntry $entry): self
    {
        return new self(
            commandId: $entry->command_id,
            type: $entry->type,
            status: $entry->status,
        );
    }
}

<?php

namespace App\DTO\Communication;

use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\InboxStatus;

final readonly class InboxCommandResult
{
    public function __construct(
        public ?string $commandId,
        public GatewayCommandType $type,
        public InboxStatus $status,
        public ?bool $deleted = null,
    ) {}
}

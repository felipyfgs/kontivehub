<?php

namespace App\DTO\Communication;

use App\Enums\Communication\FlowStatus;

final readonly class CommunicationFlowUpdateData
{
    public function __construct(
        public ?string $name,
        public ?FlowStatus $status,
        public int $lockVersion,
    ) {}
}

<?php

namespace App\DTO\Communication;

use App\Models\CommunicationMessage;

final readonly class CommunicationMessageCreationResult
{
    public function __construct(
        public CommunicationMessage $message,
        public int $httpStatus,
    ) {}
}

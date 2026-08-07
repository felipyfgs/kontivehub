<?php

namespace App\DTO\Communication;

use App\Models\CommunicationMessageBatch;

final readonly class MessageBatchCreationResult
{
    public function __construct(
        public CommunicationMessageBatch $batch,
        public int $httpStatus,
    ) {}
}

<?php

namespace App\DTO\Communication;

use App\Models\CommunicationConversationBulkOperation;

final readonly class ConversationBulkOperationAdmissionResult
{
    public function __construct(
        public CommunicationConversationBulkOperation $operation,
        public bool $created,
    ) {}
}

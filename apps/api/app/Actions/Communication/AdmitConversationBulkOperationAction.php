<?php

namespace App\Actions\Communication;

use App\DTO\Communication\ConversationBulkOperationAdmissionData;
use App\DTO\Communication\ConversationBulkOperationAdmissionResult;
use App\Services\Communication\Conversation\ConversationBulkOperationService;

final readonly class AdmitConversationBulkOperationAction
{
    public function __construct(
        private ConversationBulkOperationService $operations,
    ) {}

    public function execute(
        ConversationBulkOperationAdmissionData $data,
    ): ConversationBulkOperationAdmissionResult {
        return $this->operations->admit($data);
    }
}

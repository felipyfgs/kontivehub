<?php

namespace App\Enums\Communication;

enum ConversationBulkOperationStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case CompletedWithErrors = 'COMPLETED_WITH_ERRORS';
    case Failed = 'FAILED';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::CompletedWithErrors,
            self::Failed,
        ], true);
    }
}

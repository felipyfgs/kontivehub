<?php

namespace App\Enums\Communication;

enum ConversationBulkItemStatus: string
{
    case Queued = 'QUEUED';
    case Processing = 'PROCESSING';
    case Succeeded = 'SUCCEEDED';
    case Skipped = 'SKIPPED';
    case Failed = 'FAILED';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::Skipped,
            self::Failed,
        ], true);
    }
}

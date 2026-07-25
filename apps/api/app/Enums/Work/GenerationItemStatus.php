<?php

namespace App\Enums\Work;

enum GenerationItemStatus: string
{
    case Previewed = 'PREVIEWED';
    case Queued = 'QUEUED';
    case Created = 'CREATED';
    case SkippedDuplicate = 'SKIPPED_DUPLICATE';
    case SkippedBlocked = 'SKIPPED_BLOCKED';
    case Failed = 'FAILED';
    case Retryable = 'RETRYABLE';
}

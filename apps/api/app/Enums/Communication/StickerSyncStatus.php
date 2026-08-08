<?php

namespace App\Enums\Communication;

enum StickerSyncStatus: string
{
    case Partial = 'PARTIAL';
    case NotObserved = 'NOT_OBSERVED';
    case Syncing = 'SYNCING';
    case Failed = 'FAILED';
}

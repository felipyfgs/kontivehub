<?php

namespace App\Enums\Communication;

enum StickerAvailability: string
{
    case Available = 'AVAILABLE';
    case PendingMaterialization = 'PENDING_MATERIALIZATION';
    case IncompleteMetadata = 'INCOMPLETE_METADATA';
    case Expired = 'EXPIRED';
    case Unsupported = 'UNSUPPORTED';
    case IntegrityFailed = 'INTEGRITY_FAILED';
    case QuotaBlocked = 'QUOTA_BLOCKED';
    case Unreadable = 'UNREADABLE';
}

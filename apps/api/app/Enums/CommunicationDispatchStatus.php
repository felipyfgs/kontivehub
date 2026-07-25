<?php

namespace App\Enums;

enum CommunicationDispatchStatus: string
{
    case NotConfigured = 'NOT_CONFIGURED';
    case NoHistory = 'NO_HISTORY';
    case Scheduled = 'SCHEDULED';
    case Queued = 'QUEUED';
    case Accepted = 'ACCEPTED';
    case Sent = 'SENT';
    case Delivered = 'DELIVERED';
    case Read = 'READ';
    case Partial = 'PARTIAL';
    case Failed = 'FAILED';
    case Unknown = 'UNKNOWN';
    case SkippedNoDocument = 'SKIPPED_NO_DOCUMENT';
    case Canceled = 'CANCELED';
}

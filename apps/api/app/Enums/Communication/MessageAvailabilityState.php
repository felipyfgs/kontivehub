<?php

namespace App\Enums\Communication;

enum MessageAvailabilityState: string
{
    case Available = 'AVAILABLE';
    case Unsupported = 'UNSUPPORTED';
    case MediaRetryAvailable = 'MEDIA_RETRY_AVAILABLE';
    case MediaRequested = 'MEDIA_REQUESTED';
    case MediaFailed = 'MEDIA_FAILED';
    case Unavailable = 'UNAVAILABLE';
}

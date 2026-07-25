<?php

namespace App\Enums;

enum OutboundRetrievalStatus: string
{
    case Pending = 'PENDING';
    case Requested = 'REQUESTED';
    case Processing = 'PROCESSING';
    case Ready = 'READY';
    case Downloaded = 'DOWNLOADED';
    case Ingested = 'INGESTED';
    case Expired = 'EXPIRED';
    case Failed = 'FAILED';
    case AssistedUpload = 'ASSISTED_UPLOAD';
}

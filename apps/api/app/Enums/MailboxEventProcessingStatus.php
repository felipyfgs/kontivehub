<?php

namespace App\Enums;

enum MailboxEventProcessingStatus: string
{
    case Pending = 'PENDING';
    case Directed = 'DIRECTED';
    case Ignored = 'IGNORED';
    case Failed = 'FAILED';
}

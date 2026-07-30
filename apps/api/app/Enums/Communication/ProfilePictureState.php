<?php

namespace App\Enums\Communication;

enum ProfilePictureState: string
{
    case Unknown = 'UNKNOWN';
    case Pending = 'PENDING';
    case Ready = 'READY';
    case Unavailable = 'UNAVAILABLE';
    case Failed = 'FAILED';
}

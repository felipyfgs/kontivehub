<?php

namespace App\Enums;

enum SyncCursorStatus: string
{
    case Idle = 'IDLE';
    case Running = 'RUNNING';
    case Waiting = 'WAITING';
    case Blocked = 'BLOCKED';
    case Error = 'ERROR';
}

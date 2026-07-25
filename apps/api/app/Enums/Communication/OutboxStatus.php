<?php

namespace App\Enums\Communication;

enum OutboxStatus: string
{
    case Pending = 'PENDING';
    case Dispatching = 'DISPATCHING';
    case Accepted = 'ACCEPTED';
    case Retry = 'RETRY';
    case Dead = 'DEAD';
    case Canceled = 'CANCELED';
}

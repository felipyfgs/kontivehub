<?php

namespace App\Enums\Communication;

enum FlowRunStepStatus: string
{
    case Pending = 'pending';
    case Entered = 'entered';
    case WaitingOutbox = 'waiting_outbox';
    case WaitingInput = 'waiting_input';
    case WaitingDelay = 'waiting_delay';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Failed = 'failed';
}

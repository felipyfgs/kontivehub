<?php

namespace App\Jobs;

use App\Services\Outbound\OutboundDeadlinePlannerService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Planejamento de prazos/capacidade — nunca materializa PFX nem reserva egress.
 */
class PlanOutboundDeadlineScheduleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public ?int $officeId = null)
    {
        $this->onQueue((string) config('outbound_deadline.queue', 'capture-outbound-ma'));
    }

    public function handle(OutboundDeadlinePlannerService $planner): void
    {
        if (! config('outbound_deadline.planner_enabled') && ! config('outbound_deadline.enabled')) {
            return;
        }

        $planner->plan($this->officeId);
    }
}

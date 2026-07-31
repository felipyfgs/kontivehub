<?php

namespace App\Console\Commands;

use App\Services\Work\RoutineRecurrenceDispatcher;
use Illuminate\Console\Command;

class DispatchDueWorkRecurrenceCommand extends Command
{
    protected $signature = 'work:dispatch-due-recurrence';

    protected $description = 'Dispara Lotes de geração de Rotinas com recorrência devida (fuso do Escritório, catch-up idempotente)';

    public function handle(RoutineRecurrenceDispatcher $dispatcher): int
    {
        $result = $dispatcher->dispatchDue();

        $this->info(sprintf(
            'Work recurrence: dispatched=%d skipped=%d failed=%d catch_up=%d',
            $result['dispatched'],
            $result['skipped'],
            $result['failed'],
            $result['catch_up'],
        ));

        return self::SUCCESS;
    }
}

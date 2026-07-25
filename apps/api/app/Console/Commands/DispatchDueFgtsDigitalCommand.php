<?php

namespace App\Console\Commands;

use App\Services\FgtsDigital\FgtsDigitalScheduleDispatcher;
use Illuminate\Console\Command;

final class DispatchDueFgtsDigitalCommand extends Command
{
    protected $signature = 'fgts-digital:dispatch-due';

    protected $description = 'Enfileira consultas e políticas opt-in do FGTS Digital que estejam devidas';

    public function handle(FgtsDigitalScheduleDispatcher $dispatcher): int
    {
        $result = $dispatcher->dispatchDue();
        $this->info(sprintf(
            'FGTS Digital: dispatched=%d blocked=%d skipped=%d',
            $result['dispatched'],
            $result['blocked'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}

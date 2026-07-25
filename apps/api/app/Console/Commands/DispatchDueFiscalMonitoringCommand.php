<?php

namespace App\Console\Commands;

use App\Services\FiscalMonitoring\FiscalMonitoringScheduler;
use Illuminate\Console\Command;

class DispatchDueFiscalMonitoringCommand extends Command
{
    protected $signature = 'fiscal:dispatch-due-monitoring';

    protected $description = 'Enfileira monitoramentos fiscais devidos com espalhamento e fila justa entre tenants';

    public function handle(FiscalMonitoringScheduler $scheduler): int
    {
        $result = $scheduler->dispatchDue();

        $this->info(sprintf(
            'Fiscal monitoring: dispatched=%d skipped=%d blocked=%d',
            $result['dispatched'],
            $result['skipped'],
            $result['blocked'],
        ));

        return self::SUCCESS;
    }
}

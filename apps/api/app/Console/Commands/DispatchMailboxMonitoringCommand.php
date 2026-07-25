<?php

namespace App\Console\Commands;

use App\Services\Integra\Mailbox\MailboxMonitoringScheduler;
use Illuminate\Console\Command;

final class DispatchMailboxMonitoringCommand extends Command
{
    protected $signature = 'mailbox:dispatch-due-monitoring';

    protected $description = 'Enfileira monitoramento econômico diário da Caixa Postal por escritório';

    public function handle(MailboxMonitoringScheduler $scheduler): int
    {
        $this->info('Jobs enfileirados: '.$scheduler->dispatchDue());

        return self::SUCCESS;
    }
}

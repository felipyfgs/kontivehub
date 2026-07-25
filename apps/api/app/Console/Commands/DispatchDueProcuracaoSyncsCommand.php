<?php

namespace App\Console\Commands;

use App\Services\Integra\ClientProcuracaoAutoSyncDispatcher;
use Illuminate\Console\Command;

/** Despacha verificações oficiais somente se todas as travas explícitas permitirem. */
final class DispatchDueProcuracaoSyncsCommand extends Command
{
    protected $signature = 'serpro:dispatch-due-procuracao-syncs';

    protected $description = 'Despacha validações periódicas de procuração quando autorizadas explicitamente';

    public function handle(ClientProcuracaoAutoSyncDispatcher $dispatcher): int
    {
        $result = $dispatcher->dispatchDue();
        $skipped = collect($result['skipped'])
            ->map(fn (int $count, string $code): string => $code.'='.$count)
            ->implode(', ');

        $this->info(sprintf(
            'Procurações: lock=%s dispatched=%d skipped=%s',
            $result['lock_acquired'] ? 'acquired' : 'busy',
            $result['dispatched'],
            $skipped !== '' ? $skipped : '0',
        ));

        return self::SUCCESS;
    }
}

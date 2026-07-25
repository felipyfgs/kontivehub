<?php

namespace App\Console\Commands;

use App\Jobs\Mailbox\ProcessEventosLocalResultJob;
use App\Models\SerproEventosRun;
use App\Services\Integra\Mailbox\MailboxEventosResultProcessor;
use Illuminate\Console\Command;

final class RecoverMailboxEventosCommand extends Command
{
    protected $signature = 'mailbox:recover-eventos {run? : ID da run; omitido recupera todas pendentes}';

    protected $description = 'Retoma apenas o processamento local de resultados one-shot já recebidos';

    public function handle(): int
    {
        $query = SerproEventosRun::query()->withoutGlobalScopes()
            ->where(fn ($q) => $q->where('result_consumed', true)->orWhere('one_shot_complete', true))
            ->where('local_processing_status', '!=', MailboxEventosResultProcessor::LOCAL_SUCCEEDED);
        if ($this->argument('run') !== null) {
            $query->whereKey((int) $this->argument('run'));
        }
        $ids = $query->orderBy('id')->pluck('id');
        foreach ($ids as $id) {
            ProcessEventosLocalResultJob::dispatch((int) $id);
        }
        $this->info('Recuperações locais enfileiradas: '.$ids->count());

        return self::SUCCESS;
    }
}

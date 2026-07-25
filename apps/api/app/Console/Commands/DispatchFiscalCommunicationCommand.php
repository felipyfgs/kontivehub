<?php

namespace App\Console\Commands;

use App\Enums\CommunicationDispatchStatus;
use App\Models\ClientCommunicationDispatch;
use App\Services\Communication\Automation\FiscalCommunicationAutomationService;
use Illuminate\Console\Command;
use Throwable;

final class DispatchFiscalCommunicationCommand extends Command
{
    protected $signature = 'communication:dispatch-fiscal {--limit=200}';

    protected $description = 'Processa cutoffs de comunicação fiscal com documento canônico exato.';

    public function handle(FiscalCommunicationAutomationService $automation): int
    {
        if (! config('communication.enabled') || ! config('communication.gateway.enabled')) {
            return self::SUCCESS;
        }
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $ids = ClientCommunicationDispatch::query()->withoutGlobalScopes()
            ->where('execution_mode', 'WHATSAPP_NATIVE')
            ->where('status', CommunicationDispatchStatus::Scheduled->value)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            try {
                $automation->process((int) $id);
            } catch (Throwable) {
                $automation->failUnexpected((int) $id);
            }
        }

        return self::SUCCESS;
    }
}

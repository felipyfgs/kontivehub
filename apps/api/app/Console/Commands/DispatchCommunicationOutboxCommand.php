<?php

namespace App\Console\Commands;

use App\Enums\Communication\OutboxStatus;
use App\Jobs\Communication\DispatchCommunicationOutboxJob;
use App\Models\CommunicationOutboxEntry;
use Illuminate\Console\Command;

final class DispatchCommunicationOutboxCommand extends Command
{
    protected $signature = 'communication:dispatch-outbox {--limit=200}';

    protected $description = 'Reagenda comandos de comunicação pendentes ou com lease expirado.';

    public function handle(): int
    {
        if (! config('communication.enabled') || ! config('communication.gateway.enabled')) {
            return self::SUCCESS;
        }
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $ids = CommunicationOutboxEntry::query()
            ->withoutGlobalScopes()
            ->where(function ($query): void {
                $query->where(function ($pending): void {
                    $pending->whereIn('status', [OutboxStatus::Pending->value, OutboxStatus::Retry->value])
                        ->where('available_at', '<=', now());
                })->orWhere(function ($stale): void {
                    $stale->where('status', OutboxStatus::Dispatching->value)
                        ->where('locked_at', '<=', now()->subMinutes(2));
                });
            })
            ->orderBy('available_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            DispatchCommunicationOutboxJob::dispatch((int) $id);
        }

        return self::SUCCESS;
    }
}

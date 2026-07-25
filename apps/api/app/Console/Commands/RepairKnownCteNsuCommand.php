<?php

namespace App\Console\Commands;

use App\Enums\CaptureChannel;
use App\Enums\SyncCursorStatus;
use App\Jobs\RepairKnownCteNsuJob;
use App\Models\ChannelSyncCursor;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RepairKnownCteNsuCommand extends Command
{
    protected $signature = 'sefaz:cte-repair-nsu {cursor : ID do cursor CT-e} {nsu : NSU conhecido e positivo}';

    protected $description = 'Enfileira consNSU CT-e conhecido sem avançar o cursor sequencial';

    public function handle(): int
    {
        $nsu = (int) $this->argument('nsu');
        $cursor = ChannelSyncCursor::query()->find((int) $this->argument('cursor'));
        if ($nsu < 1 || $cursor === null || $cursor->channel !== CaptureChannel::CteDistDfe) {
            $this->error('Cursor CT-e e NSU positivo são obrigatórios.');

            return self::FAILURE;
        }
        if ($cursor->status === SyncCursorStatus::Blocked || ($cursor->next_sync_at?->isFuture() ?? false)) {
            $this->error('Reparo recusado durante circuito ou quiet period.');

            return self::FAILURE;
        }

        $correlationId = (string) Str::uuid();
        RepairKnownCteNsuJob::dispatch($cursor->id, $nsu, $correlationId);
        $this->info("Reparo enfileirado: {$correlationId}");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Enums\Communication\ConversationBulkOperationStatus;
use App\Models\CommunicationConversationBulkOperation;
use App\Models\CommunicationConversationBulkOperationItem;
use App\Support\LogSanitizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PurgeExpiredConversationBulkOperationsCommand extends Command
{
    protected $signature = 'communication:purge-expired-bulk-operations {--days=30 : Retention window in days} {--limit=500 : Max operations per run}';

    protected $description = 'Remove terminal conversation bulk operations older than the retention window (does not delete CommunicationEvent)';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $limit = max(1, (int) $this->option('limit'));
        $cutoff = now()->subDays($days);
        $purged = 0;

        $operations = CommunicationConversationBulkOperation::query()
            ->withoutGlobalScopes()
            ->whereIn('status', [
                ConversationBulkOperationStatus::Completed->value,
                ConversationBulkOperationStatus::CompletedWithErrors->value,
                ConversationBulkOperationStatus::Failed->value,
            ])
            ->where(static function ($query) use ($cutoff): void {
                $query->where('completed_at', '<=', $cutoff)
                    ->orWhere(static function ($inner) use ($cutoff): void {
                        $inner->whereNull('completed_at')
                            ->where('updated_at', '<=', $cutoff);
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'public_id', 'tenant_id']);

        foreach ($operations as $operation) {
            try {
                DB::transaction(function () use ($operation): void {
                    CommunicationConversationBulkOperationItem::query()
                        ->withoutGlobalScopes()
                        ->where('bulk_operation_id', $operation->id)
                        ->delete();
                    CommunicationConversationBulkOperation::query()
                        ->withoutGlobalScopes()
                        ->whereKey($operation->id)
                        ->delete();
                }, 3);
                $purged++;
            } catch (Throwable $error) {
                Log::warning('communication.bulk_operation.retention_purge_failed', [
                    'operation_id' => $operation->public_id,
                    'tenant_id' => (int) $operation->tenant_id,
                    'error' => LogSanitizer::scrubString($error->getMessage()),
                ]);
            }
        }

        if ($purged > 0) {
            Log::info('communication.bulk_operation.retention_purged', [
                'purged_count' => $purged,
                'days' => $days,
            ]);
        }

        $this->info(sprintf(
            'Purged %d terminal bulk operation(s) older than %d day(s).',
            $purged,
            $days,
        ));

        return self::SUCCESS;
    }
}

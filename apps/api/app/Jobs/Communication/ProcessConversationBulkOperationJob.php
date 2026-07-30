<?php

namespace App\Jobs\Communication;

use App\Models\CommunicationConversationBulkOperation;
use App\Services\Communication\Conversation\ConversationBulkOperationProcessor;
use App\Support\LogSanitizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProcessConversationBulkOperationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 120;

    /** @var list<int> */
    public array $backoff = [5, 15, 30, 60];

    public function __construct(public readonly int $operationId)
    {
        $this->onQueue('communication');
        $this->afterCommit();
    }

    public function handle(ConversationBulkOperationProcessor $processor): void
    {
        $processor->process($this->operationId);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('communication.bulk_operation.job_failed', [
            'operation_id' => $this->operationId,
            'error' => LogSanitizer::scrubString((string) ($exception?->getMessage() ?? '')),
        ]);

        $operation = CommunicationConversationBulkOperation::query()
            ->withoutGlobalScopes()
            ->find($this->operationId);
        if ($operation === null) {
            return;
        }

        app(ConversationBulkOperationProcessor::class)->failOperation(
            operation: $operation,
            code: 'JOB_FAILED',
            message: 'Falha ao processar operação em lote.',
        );
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['communication', 'conversation-bulk', 'operation:'.$this->operationId];
    }
}

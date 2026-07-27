<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Support\CurrentTenant;
use App\Support\LogSanitizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Trilha de auditoria append-only + log estruturado sem segredos.
 * Hash encadeado (prev_hash/entry_hash).
 * Sanitização central em {@see LogSanitizer}.
 */
final class AuditLogger
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        string $action,
        string $result = 'SUCCESS',
        ?Model $subject = null,
        array $context = [],
        ?int $userId = null,
        ?int $tenantId = null,
    ): void {
        $safe = $this->redact($context);
        $correlationId = $this->correlationId();
        $createdAt = now();

        try {
            DB::transaction(function () use (
                $action,
                $result,
                $subject,
                $safe,
                $userId,
                $tenantId,
                $correlationId,
                $createdAt,
            ): void {
                $prev = AuditLog::query()
                    ->whereNotNull('entry_hash')
                    ->orderByDesc('chain_seq')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                $prevHash = $prev?->entry_hash ?? str_repeat('0', 64);
                $chainSeq = $prev !== null && $prev->chain_seq !== null
                    ? ((int) $prev->chain_seq) + 1
                    : 1;

                $tenantIdVal = $tenantId ?? $this->currentTenant->id();
                $userIdVal = $userId ?? auth()->id();
                $subjectId = $subject?->getKey();

                $payload = [
                    'chain_seq' => $chainSeq,
                    'tenant_id' => $tenantIdVal !== null ? (int) $tenantIdVal : null,
                    'user_id' => $userIdVal !== null ? (int) $userIdVal : null,
                    'action' => $action,
                    'subject_type' => $subject ? $subject::class : null,
                    'subject_id' => $subjectId !== null ? (int) $subjectId : null,
                    'result' => $result,
                    'context' => $safe,
                    'correlation_id' => $correlationId,
                    'prev_hash' => $prevHash,
                ];

                AuditLog::query()->create([
                    'chain_seq' => $chainSeq,
                    'tenant_id' => $payload['tenant_id'],
                    'user_id' => $payload['user_id'],
                    'action' => $action,
                    'subject_type' => $payload['subject_type'],
                    'subject_id' => $payload['subject_id'],
                    'result' => $result,
                    'context' => $safe,
                    'ip_address' => request()?->ip(),
                    'user_agent' => request()?->userAgent(),
                    'correlation_id' => $correlationId,
                    'prev_hash' => $prevHash,
                    'entry_hash' => $this->computeEntryHash($payload),
                    'created_at' => $createdAt,
                ]);
            });
        } catch (Throwable $e) {
            // Auditoria não deve derrubar a operação principal.
            report($e);
        }

        Log::info('audit.'.$action, [
            'result' => $result,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'tenant_id' => $tenantId ?? $this->currentTenant->id(),
            'user_id' => $userId ?? auth()->id(),
            'correlation_id' => $correlationId,
            'context' => $safe,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function computeEntryHash(array $payload): string
    {
        $canonical = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function redact(array $context): array
    {
        return LogSanitizer::redact($context);
    }

    public function correlationId(): string
    {
        $existing = request()?->attributes->get('correlation_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $id = (string) Str::uuid();
        request()?->attributes->set('correlation_id', $id);

        return $id;
    }
}

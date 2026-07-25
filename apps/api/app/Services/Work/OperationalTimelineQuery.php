<?php

namespace App\Services\Work;

use App\Models\AuditLog;
use App\Models\OperationalComment;
use App\Models\OperationalProcess;
use App\Models\OperationalTask;
use App\Models\OperationalTaskEvidence;
use App\Support\LogSanitizer;
use Illuminate\Support\Collection;

/**
 * Timeline allowlisted: auditoria + comentários + evidências (sem payload bruto).
 */
final class OperationalTimelineQuery
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forProcess(OperationalProcess $process): array
    {
        $taskIds = $process->tasks()->pluck('id')->all();

        $audits = AuditLog::query()
            ->where('office_id', $process->office_id)
            ->where(function ($q) use ($process, $taskIds): void {
                $q->where(function ($inner) use ($process): void {
                    $inner->where('subject_type', OperationalProcess::class)
                        ->where('subject_id', $process->id);
                });
                if ($taskIds !== []) {
                    $q->orWhere(function ($inner) use ($taskIds): void {
                        $inner->where('subject_type', OperationalTask::class)
                            ->whereIn('subject_id', $taskIds);
                    });
                }
            })
            ->where('action', 'like', 'work.%')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (AuditLog $a) => [
                'kind' => 'audit',
                'action' => $a->action,
                'result' => $a->result,
                'created_at' => $a->created_at?->toIso8601String(),
                'context' => $this->sanitizeContext($a->context ?? []),
            ]);

        $comments = OperationalComment::query()
            ->where('office_id', $process->office_id)
            ->where(function ($q) use ($process, $taskIds): void {
                $q->where('operational_process_id', $process->id);
                if ($taskIds !== []) {
                    $q->orWhereIn('operational_task_id', $taskIds);
                }
            })
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (OperationalComment $c) => [
                'kind' => 'comment',
                'body' => $c->body,
                'author_membership_id' => $c->author_membership_id,
                'task_id' => $c->operational_task_id,
                'created_at' => $c->created_at?->toIso8601String(),
            ]);

        $evidences = OperationalTaskEvidence::query()
            ->where('office_id', $process->office_id)
            ->whereIn('operational_task_id', $taskIds ?: [0])
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (OperationalTaskEvidence $e) => [
                'kind' => 'evidence',
                'id' => $e->id,
                'task_id' => $e->operational_task_id,
                'original_filename' => $e->original_filename,
                'mime_type' => $e->mime_type,
                'byte_size' => $e->byte_size,
                'removed' => $e->removed_at !== null,
                'created_at' => $e->created_at?->toIso8601String(),
                // sem vault_object_id / sha em timeline pública opcional — sha ok, vault não
            ]);

        return Collection::make()
            ->merge($audits)
            ->merge($comments)
            ->merge($evidences)
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        return LogSanitizer::redact($context);
    }
}

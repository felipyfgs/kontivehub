<?php

namespace App\Actions\Work;

use App\DTO\Work\CommentData;
use App\Models\WorkComment;
use App\Models\WorkTask;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class CreateWorkTaskCommentAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuditLogger $audit,
    ) {}

    public function execute(
        WorkTask $task,
        CommentData $data,
    ): WorkComment {
        return DB::transaction(function () use ($task, $data): WorkComment {
            $comment = WorkComment::query()->create([
                'tenant_id' => $this->currentTenant->id(),
                'work_process_id' => $task->work_process_id,
                'work_task_id' => $task->id,
                'author_membership_id' => $this->currentTenant->realMembership()?->id,
                'body' => $data->body,
            ]);

            $this->audit->record('work.comment.create', 'SUCCESS', $comment, [
                'target' => 'task',
                'task_id' => $task->id,
            ]);

            return $comment;
        });
    }
}

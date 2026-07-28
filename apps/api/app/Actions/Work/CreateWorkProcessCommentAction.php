<?php

namespace App\Actions\Work;

use App\DTO\Work\WorkCommentData;
use App\Models\WorkComment;
use App\Models\WorkProcess;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class CreateWorkProcessCommentAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuditLogger $audit,
    ) {}

    public function execute(
        WorkProcess $process,
        WorkCommentData $data,
    ): WorkComment {
        return DB::transaction(function () use ($process, $data): WorkComment {
            $comment = WorkComment::query()->create([
                'tenant_id' => $this->currentTenant->id(),
                'work_process_id' => $process->id,
                'work_task_id' => null,
                'author_membership_id' => $this->currentTenant->realMembership()?->id,
                'body' => $data->body,
            ]);

            $this->audit->record('work.comment.create', 'SUCCESS', $comment, [
                'target' => 'process',
                'process_id' => $process->id,
            ]);

            return $comment;
        });
    }
}

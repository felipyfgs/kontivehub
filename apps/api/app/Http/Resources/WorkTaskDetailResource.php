<?php

namespace App\Http\Resources;

use App\DTO\Work\TaskDetailData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaskDetailData */
final class WorkTaskDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaskDetailData $detail */
        $detail = $this->resource;
        $task = $detail->task;
        $process = $task->process;
        $data = WorkTaskResource::make($task)->resolve($request);

        $data['risks'] = $detail->risks;
        $data['effective_due_date'] = $detail->effectiveDueDate;
        $data['bucket'] = $detail->bucket;
        $data['evidences'] = WorkTaskEvidenceResource::collection(
            $task->evidences,
        )->resolve($request);
        $data['comments'] = WorkTaskCommentResource::collection(
            $task->comments,
        )->resolve($request);
        if ($process !== null) {
            $data['process'] = [
                'id' => $process->id,
                'title' => $process->title,
                'competence' => $process->competence,
                'status' => $process->status->value,
                'subject_to_fine' => (bool) $process->subject_to_fine,
                'due_date' => $process->due_date?->format('Y-m-d'),
                'client' => $process->client ? [
                    'id' => $process->client->id,
                    'name' => $process->client->display_name
                        ?: $process->client->legal_name,
                ] : null,
            ];
        }

        return $data;
    }
}

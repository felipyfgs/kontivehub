<?php

namespace App\Http\Resources;

use App\DTO\Work\TaskQueueItemData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaskQueueItemData */
final class WorkTaskQueueItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaskQueueItemData $item */
        $item = $this->resource;
        $task = $item->task;

        return [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status->value,
            'due_date' => $task->due_date?->format('Y-m-d'),
            'effective_due_date' => $item->effectiveDueDate,
            'is_critical' => $task->is_critical,
            'is_required' => $task->is_required,
            'requires_evidence' => $task->requires_evidence,
            'block_reason' => $task->block_reason,
            'lock_version' => $task->lock_version,
            'bucket' => $item->bucket,
            'risks' => $item->risks,
            'department' => $task->department ? [
                'id' => $task->department->id,
                'name' => $task->department->name,
                'code' => $task->department->code,
            ] : null,
            'assignee' => $task->assigneeMembership?->user ? [
                'membership_id' => $task->assigneeMembership->id,
                'name' => $task->assigneeMembership->user->name,
            ] : null,
            'process' => $task->process ? [
                'id' => $task->process->id,
                'title' => $task->process->title,
                'competence' => $task->process->competence,
                'status' => $task->process->status->value,
                'subject_to_fine' => $task->process->subject_to_fine,
                'client' => $task->process->client ? [
                    'id' => $task->process->client->id,
                    'name' => $task->process->client->display_name
                        ?: $task->process->client->legal_name,
                ] : null,
            ] : null,
        ];
    }
}

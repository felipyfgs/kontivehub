<?php

namespace App\Http\Resources\Work;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProcessGroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $group */
        $group = $this->resource;
        $data = [
            'key' => $group['key'],
            'label' => $group['label'],
        ];

        if (array_key_exists('client', $group)) {
            $data['client'] = $group['client'];
        }
        if (array_key_exists('routine', $group)) {
            $data['routine'] = $group['routine'];
        }

        return $data + [
            'client_count' => $group['client_count'],
            'process_count' => $group['process_count'],
            'task_count' => $group['task_count'],
            'open_task_count' => $group['open_task_count'],
            'completed_task_count' => $group['completed_task_count'],
            'progress_percent' => $group['progress_percent'],
            'status_counts' => $group['status_counts'],
            'next_due_date' => $group['next_due_date'],
        ];
    }
}

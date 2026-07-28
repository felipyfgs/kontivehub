<?php

namespace App\Http\Resources;

use App\Domain\Work\ReferencePeriod;
use App\Domain\Work\WorkRiskCalculator;
use App\DTO\Work\WorkProcessTaskViewData;
use App\DTO\Work\WorkProcessViewData;
use App\Enums\Work\TaskStatus;
use App\Models\Client;
use App\Models\WorkTask;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use InvalidArgumentException;

/** @mixin WorkProcessViewData */
final class WorkProcessResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkProcessViewData $view */
        $view = $this->resource;
        $process = $view->process;
        $counts = $this->taskCounts($process);
        $data = [
            'id' => $process->id,
            'title' => $process->title,
            'description' => $process->description,
            'monitoring_module_key' => $process->monitoring_module_key,
            'competence' => $process->competence,
            'reference_period' => $this->referencePeriod($process),
            'origin' => $process->origin->value,
            'status' => $process->status->value,
            'archived_at' => $process->archived_at?->toIso8601String(),
            'is_archived' => $process->archived_at !== null,
            'due_date' => $process->due_date?->format('Y-m-d'),
            'target_due_date' => $process->target_due_date?->format('Y-m-d'),
            'subject_to_fine' => $process->subject_to_fine,
            'work_department_id' => $process->work_department_id,
            'assignee_membership_id' => $process->assignee_membership_id,
            'client_id' => $process->client_id,
            'work_process_template_id' => $process->work_process_template_id,
            'lock_version' => $process->lock_version,
            'client' => $process->relationLoaded('client') && $process->client ? [
                'id' => $process->client->id,
                'name' => $process->client->display_name
                    ?: $process->client->legal_name,
                'cnpj_masked' => $this->clientCnpjMasked($process->client),
            ] : null,
            'links' => $process->client_id ? [
                'client' => "/clients/{$process->client_id}/cadastro",
                'monitoring' => "/monitoring/clients/{$process->client_id}",
            ] : null,
            'monitoring_context' => $view->monitoringContext,
            'department' => $process->relationLoaded('department')
                && $process->department ? [
                    'id' => $process->department->id,
                    'name' => $process->department->name,
                    'code' => $process->department->code,
                ] : null,
            'assignee' => $process->relationLoaded('assigneeMembership')
                && $process->assigneeMembership?->user ? [
                    'membership_id' => $process->assigneeMembership->id,
                    'name' => $process->assigneeMembership->user->name,
                ] : null,
            'task_count' => $counts['total'],
            'completed_task_count' => $counts['completed'],
            'open_task_count' => $counts['open'],
            'progress_percent' => $counts['progress'],
            'risks' => $this->risks($view),
        ];

        if ($view->includeTasks && $process->relationLoaded('tasks')) {
            $data['tasks'] = WorkProcessTaskResource::collection(
                $process->tasks->map(
                    fn (WorkTask $task) => new WorkProcessTaskViewData(
                        $task,
                        $process,
                        $view->today,
                    ),
                ),
            )->resolve($request);
        }
        if ($view->detailed && $process->relationLoaded('comments')) {
            $data['comments'] = WorkProcessCommentResource::collection(
                $process->comments,
            )->resolve($request);
        }

        return $data;
    }

    /**
     * @return array{total: int|null, completed: int|null, open: int|null, progress: int|null}
     */
    private function taskCounts($process): array
    {
        if ($process->relationLoaded('tasks')) {
            $total = $process->tasks->count();
            $completed = $process->tasks->filter(
                fn (WorkTask $task) => in_array(
                    $task->status,
                    [TaskStatus::Concluida, TaskStatus::Dispensada],
                    true,
                ),
            )->count();
            $open = $process->tasks->filter(
                fn (WorkTask $task) => ! $task->status->isTerminal(),
            )->count();

            return [
                'total' => $total,
                'completed' => $completed,
                'open' => $open,
                'progress' => $total > 0
                    ? (int) round(($completed / $total) * 100)
                    : 0,
            ];
        }
        if (isset($process->tasks_count)
            || isset($process->completed_task_count)
            || isset($process->open_task_count)) {
            $total = (int) ($process->tasks_count ?? 0);
            $completed = (int) ($process->completed_task_count ?? 0);

            return [
                'total' => $total,
                'completed' => $completed,
                'open' => (int) ($process->open_task_count ?? 0),
                'progress' => $total > 0
                    ? (int) round(($completed / $total) * 100)
                    : 0,
            ];
        }

        return [
            'total' => null,
            'completed' => null,
            'open' => null,
            'progress' => null,
        ];
    }

    /** @return list<string> */
    private function risks(WorkProcessViewData $view): array
    {
        $process = $view->process;
        if (! $process->relationLoaded('tasks')) {
            return [];
        }

        $calculator = new WorkRiskCalculator;
        $risks = [];
        foreach ($process->tasks as $task) {
            if ($task->status->isTerminal()) {
                continue;
            }
            foreach ($calculator->forTask(
                $task->status,
                $task->due_date?->format('Y-m-d'),
                $process->target_due_date?->format('Y-m-d'),
                $process->due_date?->format('Y-m-d'),
                (bool) $process->subject_to_fine,
                $task->assignee_membership_id,
                $view->today,
            ) as $risk) {
                $risks[$risk->value] = true;
            }
        }

        return array_keys($risks);
    }

    /** @return array{type: string, key: string, start: string, end: string}|null */
    private function referencePeriod($process): ?array
    {
        if ($process->reference_period_type
            && $process->reference_period_start
            && $process->reference_period_end) {
            return [
                'type' => (string) $process->reference_period_type,
                'key' => (string) $process->competence,
                'start' => $process->reference_period_start->format('Y-m-d'),
                'end' => $process->reference_period_end->format('Y-m-d'),
            ];
        }

        try {
            return ReferencePeriod::fromString(
                (string) $process->competence,
            )->toArray();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function clientCnpjMasked(Client $client): ?string
    {
        $cnpj = $client->relationLoaded('establishments')
            ? $client->establishments
                ->sortByDesc('is_headquarters')
                ->first()?->cnpj
            : null;
        $digits = preg_replace('/\D+/', '', (string) $cnpj) ?? '';
        if (strlen($digits) !== 14) {
            return null;
        }

        return substr($digits, 0, 2).'.'.substr($digits, 2, 3)
            .'.'.substr($digits, 5, 3).'/'.substr($digits, 8, 4)
            .'-'.substr($digits, 12, 2);
    }
}

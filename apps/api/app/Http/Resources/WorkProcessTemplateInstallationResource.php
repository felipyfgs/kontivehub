<?php

namespace App\Http\Resources;

use App\Models\WorkProcessTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkProcessTemplateInstallationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkProcessTemplate $template */
        $template = $this->resource;

        return [
            'id' => $template->id,
            'catalog_key' => $template->catalog_key,
            'catalog_version' => $template->catalog_version,
            'name' => $template->name,
            'monitoring_module_key' => $template->monitoring_module_key,
            'audience_rules' => $template->audience_rules,
            'default_department_id' => $template->default_department_id,
            'is_active' => $template->is_active,
            'lock_version' => $template->lock_version,
            'tasks' => $template->tasks->map(static fn ($task): array => [
                'id' => $task->id,
                'sort_order' => $task->sort_order,
                'title' => $task->title,
                'description' => $task->description,
                'due_rule_type' => $task->due_rule_type?->value,
                'due_rule_value' => $task->due_rule_value,
                'default_department_id' => $task->default_department_id,
                'default_assignee_membership_id' => $task->default_assignee_membership_id,
                'is_required' => $task->is_required,
                'is_critical' => $task->is_critical,
                'requires_evidence' => $task->requires_evidence,
            ])->values(),
        ];
    }
}

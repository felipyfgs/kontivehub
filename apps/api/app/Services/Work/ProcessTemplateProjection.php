<?php

namespace App\Services\Work;

use App\Models\WorkProcessTemplate;

final class ProcessTemplateProjection
{
    /** @return array<string, mixed> */
    public function build(WorkProcessTemplate $template): array
    {
        return [
            'id' => $template->id,
            'catalog_key' => $template->catalog_key,
            'catalog_version' => $template->catalog_version,
            'name' => $template->name,
            'description' => $template->description,
            'monitoring_module_key' => $template->monitoring_module_key,
            'audience_rules' => $template->audience_rules ?? [
                'tax_regimes' => [],
                'category_ids' => [],
                'category_match' => 'ANY',
                'excluded_category_ids' => [],
            ],
            'default_department_id' => $template->default_department_id,
            'default_due_rule_type' => $template->default_due_rule_type?->value,
            'default_due_rule_value' => $template->default_due_rule_value,
            'is_active' => $template->is_active,
            'recurrence_enabled' => (bool) $template->recurrence_enabled,
            'recurrence_frequency' => $template->recurrence_frequency?->value,
            'generation_day' => (int) ($template->generation_day ?? 1),
            'anchor_month' => $template->anchor_month,
            'period_offset' => $template->period_offset?->value ?? 'PREVIOUS',
            'next_run_at' => $template->next_run_at?->toIso8601String(),
            'recurrence_owner_membership_id' => $template->recurrence_owner_membership_id,
            'lock_version' => $template->lock_version,
            'tasks' => $template->relationLoaded('tasks')
                ? $template->tasks->map(static fn ($task): array => [
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
                ])->values()->all()
                : [],
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }
}

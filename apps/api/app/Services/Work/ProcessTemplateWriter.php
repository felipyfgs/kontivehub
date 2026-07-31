<?php

namespace App\Services\Work;

use App\DTO\Work\ProcessTemplateData;
use App\Enums\Work\DueRuleType;
use App\Models\WorkProcessTemplate;
use App\Models\WorkProcessTemplateTask;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;
use App\Support\Work\OptimisticLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Caminho único de escrita de WorkProcessTemplate (HTTP Work + tools do assistente).
 */
final class ProcessTemplateWriter
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly MembershipResolver $memberships,
        private readonly ProcessAudienceResolver $audiences,
        private readonly MonitoringContextRegistry $monitoringContexts,
        private readonly ProcessTemplateRecurrenceService $recurrence,
        private readonly ProcessTemplateProjection $projection,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(ProcessTemplateData|array $input): WorkProcessTemplate
    {
        $input = $input instanceof ProcessTemplateData ? $input->attributes : $input;
        $tenantId = $this->requireTenantId();
        $data = $this->validated($input, $tenantId);
        if (array_key_exists('audience_rules', $data)) {
            $data['audience_rules'] = $this->audiences->normalizeRules($data['audience_rules'] ?? []);
        }
        $this->validateRelations($data);
        $recurrenceAttrs = $this->recurrence->hasPayload($input)
            ? $this->recurrence->attributesFromInput($input)
            : [];

        $template = DB::transaction(function () use ($data, $tenantId, $recurrenceAttrs): WorkProcessTemplate {
            $template = WorkProcessTemplate::query()->create(array_merge([
                'tenant_id' => $tenantId,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'monitoring_module_key' => $data['monitoring_module_key'] ?? null,
                'audience_rules' => $data['audience_rules'] ?? [],
                'default_department_id' => $data['default_department_id'] ?? null,
                'default_due_rule_type' => $data['default_due_rule_type'] ?? null,
                'default_due_rule_value' => $data['default_due_rule_value'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'lock_version' => 1,
                'created_by_membership_id' => $this->currentTenant->realMembership()?->id,
            ], $recurrenceAttrs));

            $this->syncTasks($template, $data['tasks'] ?? [], $tenantId);

            return $template->load('tasks');
        });

        $this->audit->record('work.template.create', 'SUCCESS', $template);
        if ($recurrenceAttrs !== []) {
            $this->audit->record('work.template.recurrence.update', 'SUCCESS', $template, [
                'recurrence_enabled' => $template->recurrence_enabled,
                'recurrence_frequency' => $template->recurrence_frequency?->value,
                'generation_day' => $template->generation_day,
                'atomic_create' => true,
            ]);
        }

        return $template;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function update(
        WorkProcessTemplate $template,
        ProcessTemplateData|array $input,
    ): WorkProcessTemplate {
        $input = $input instanceof ProcessTemplateData ? $input->attributes : $input;
        $tenantId = $this->requireTenantId();
        $data = $this->validated($input, $tenantId, $template->id);
        if (array_key_exists('audience_rules', $data)) {
            $data['audience_rules'] = $this->audiences->normalizeRules($data['audience_rules'] ?? []);
        }
        $lockVersion = (int) ($input['lock_version'] ?? $template->lock_version);
        OptimisticLock::assert($template, $lockVersion, 'process_template');
        $this->validateRelations($data);
        $applyRecurrence = $this->recurrence->hasPayload($input);
        $recurrenceAttrs = $applyRecurrence
            ? $this->recurrence->attributesFromInput($input, $template)
            : [];

        $template = DB::transaction(function () use ($template, $data, $lockVersion, $tenantId, $applyRecurrence, $recurrenceAttrs): WorkProcessTemplate {
            $attrs = collect($data)->only([
                'name', 'description', 'monitoring_module_key', 'audience_rules', 'default_department_id',
                'default_due_rule_type', 'default_due_rule_value', 'is_active',
            ])->all();

            if ($applyRecurrence) {
                $attrs = array_merge($attrs, $recurrenceAttrs);
            }

            OptimisticLock::updateOrConflict($template, $lockVersion, $attrs, 'process_template');
            $template->refresh();

            if (isset($data['tasks'])) {
                WorkProcessTemplateTask::query()
                    ->where('work_process_template_id', $template->id)
                    ->delete();
                $this->syncTasks($template, $data['tasks'], $tenantId);
                $template->forceFill(['lock_version' => $template->lock_version + 1])->save();
            }

            return $template->fresh('tasks');
        });

        $this->audit->record('work.template.update', 'SUCCESS', $template);
        if ($applyRecurrence) {
            $this->audit->record('work.template.recurrence.update', 'SUCCESS', $template, [
                'recurrence_enabled' => $template->recurrence_enabled,
                'recurrence_frequency' => $template->recurrence_frequency?->value,
                'generation_day' => $template->generation_day,
                'atomic_update' => true,
            ]);
        }

        return $template;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublic(WorkProcessTemplate $t): array
    {
        return $this->projection->build($t);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validated(array $input, ?int $tenantId = null, ?int $ignoreId = null): array
    {
        return Validator::make(
            $input,
            $this->rules($tenantId, $ignoreId),
        )->validate();
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(?int $tenantId = null, ?int $ignoreId = null): array
    {
        $tenantId ??= $this->requireTenantId();

        $nameRule = Rule::unique('work_process_templates', 'name')->where('tenant_id', $tenantId);
        if ($ignoreId !== null) {
            $nameRule = $nameRule->ignore($ignoreId);
        }

        $monitoringKeys = $this->monitoringContexts->keys();

        return [
            'name' => ['required', 'string', 'max:160', $nameRule],
            'description' => ['nullable', 'string'],
            'monitoring_module_key' => ['nullable', 'string', Rule::in($monitoringKeys)],
            'audience_rules' => ['sometimes', 'array'],
            'audience_rules.tax_regimes' => ['sometimes', 'array', 'max:6'],
            'audience_rules.tax_regimes.*' => ['string', 'max:40'],
            'audience_rules.category_ids' => ['sometimes', 'array', 'max:100'],
            'audience_rules.category_ids.*' => ['integer', 'min:1'],
            'audience_rules.category_match' => ['sometimes', 'string', Rule::in(['ANY', 'ALL'])],
            'audience_rules.excluded_category_ids' => ['sometimes', 'array', 'max:100'],
            'audience_rules.excluded_category_ids.*' => ['integer', 'min:1'],
            'default_department_id' => ['nullable', 'integer'],
            'default_due_rule_type' => ['nullable', 'string', Rule::enum(DueRuleType::class)],
            'default_due_rule_value' => ['nullable', 'integer', 'min:0', 'max:366'],
            'is_active' => ['sometimes', 'boolean'],
            'lock_version' => ['sometimes', 'integer', 'min:1'],
            'tasks' => ['sometimes', 'array', 'min:1'],
            'tasks.*.sort_order' => ['required_with:tasks', 'integer', 'min:1'],
            'tasks.*.title' => ['required_with:tasks', 'string', 'max:200'],
            'tasks.*.description' => ['nullable', 'string'],
            'tasks.*.due_rule_type' => ['nullable', 'string', Rule::enum(DueRuleType::class)],
            'tasks.*.due_rule_value' => ['nullable', 'integer', 'min:0', 'max:366'],
            'tasks.*.default_department_id' => ['nullable', 'integer'],
            'tasks.*.default_assignee_membership_id' => ['nullable', 'integer'],
            'tasks.*.is_required' => ['sometimes', 'boolean'],
            'tasks.*.is_critical' => ['sometimes', 'boolean'],
            'tasks.*.requires_evidence' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validateRelations(array $data): void
    {
        if (! empty($data['default_department_id'])) {
            $this->memberships->requireActiveDepartment((int) $data['default_department_id']);
        }
        foreach ($data['tasks'] ?? [] as $t) {
            if (! empty($t['default_department_id'])) {
                $this->memberships->requireActiveDepartment((int) $t['default_department_id']);
            }
            if (! empty($t['default_assignee_membership_id'])) {
                $this->memberships->requireActiveMembership((int) $t['default_assignee_membership_id']);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    private function syncTasks(WorkProcessTemplate $template, array $tasks, int $tenantId): void
    {
        $orders = [];
        foreach ($tasks as $t) {
            $order = (int) $t['sort_order'];
            if (isset($orders[$order])) {
                throw ValidationException::withMessages([
                    'tasks' => ['Ordens de tarefa devem ser únicas.'],
                ]);
            }
            $orders[$order] = true;

            WorkProcessTemplateTask::query()->create([
                'tenant_id' => $tenantId,
                'work_process_template_id' => $template->id,
                'sort_order' => $order,
                'title' => $t['title'],
                'description' => $t['description'] ?? null,
                'due_rule_type' => $t['due_rule_type'] ?? null,
                'due_rule_value' => $t['due_rule_value'] ?? null,
                'default_department_id' => $t['default_department_id'] ?? null,
                'default_assignee_membership_id' => $t['default_assignee_membership_id'] ?? null,
                'is_required' => $t['is_required'] ?? true,
                'is_critical' => $t['is_critical'] ?? false,
                'requires_evidence' => $t['requires_evidence'] ?? false,
            ]);
        }
    }

    private function requireTenantId(): int
    {
        $tenantId = $this->currentTenant->id();
        if ($tenantId === null) {
            abort(404);
        }

        return $tenantId;
    }
}

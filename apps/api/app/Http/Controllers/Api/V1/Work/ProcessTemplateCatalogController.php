<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Http\Controllers\Controller;
use App\Models\ProcessTemplate;
use App\Models\ProcessTemplateTask;
use App\Models\WorkDepartment;
use App\Services\Audit\AuditLogger;
use App\Services\Work\MembershipResolver;
use App\Services\Work\ProcessAudienceResolver;
use App\Services\Work\ProcessTemplateCatalog;
use App\Services\Work\WorkMonitoringContextRegistry;
use App\Support\CurrentOffice;
use App\Support\Work\RejectClientOfficeId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProcessTemplateCatalogController extends Controller
{
    public function index(
        Request $request,
        CurrentOffice $currentOffice,
        ProcessTemplateCatalog $catalog,
    ): JsonResponse {
        $this->authorize('viewAny', ProcessTemplate::class);
        RejectClientOfficeId::strip($request);

        $installed = ProcessTemplate::query()
            ->where('office_id', $currentOffice->id())
            ->whereNotNull('catalog_key')
            ->get()
            ->keyBy('catalog_key');

        $data = collect($catalog->all())->map(function (array $definition) use ($installed): array {
            /** @var ProcessTemplate|null $template */
            $template = $installed->get($definition['key']);

            return [
                ...$definition,
                'installed' => $template !== null,
                'installed_template_id' => $template?->id,
                'installed_version' => $template?->catalog_version,
                'update_available' => $template !== null
                    && (int) $template->catalog_version < (int) $definition['version'],
            ];
        })->values();

        return response()->json(['data' => $data]);
    }

    public function install(
        Request $request,
        string $catalogKey,
        CurrentOffice $currentOffice,
        ProcessTemplateCatalog $catalog,
        ProcessAudienceResolver $audiences,
        WorkMonitoringContextRegistry $monitoring,
        MembershipResolver $memberships,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorize('create', ProcessTemplate::class);
        RejectClientOfficeId::strip($request);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'default_department_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        try {
            $definition = $catalog->findOrFail($catalogKey);
        } catch (\InvalidArgumentException) {
            abort(404);
        }

        if (! $monitoring->allows($definition['monitoring_module_key'])) {
            throw ValidationException::withMessages([
                'catalog_key' => ['Modelo-base possui contexto de Monitoramento inválido.'],
            ]);
        }

        $existing = ProcessTemplate::query()
            ->where('office_id', $currentOffice->id())
            ->where('catalog_key', $definition['key'])
            ->first();
        if ($existing !== null) {
            throw ValidationException::withMessages([
                'catalog_key' => ['Este modelo-base já está instalado no escritório.'],
            ]);
        }

        $departmentId = isset($data['default_department_id']) && $data['default_department_id'] !== null
            ? (int) $data['default_department_id']
            : $this->departmentForRole($currentOffice->id(), $definition['department_role']);
        if ($departmentId !== null) {
            $memberships->requireActiveDepartment($departmentId);
        }

        $name = trim((string) ($data['name'] ?? $definition['name']));
        $name = $this->uniqueName($currentOffice->id(), $name);
        $rules = $audiences->normalizeRules($definition['audience_rules']);

        $template = DB::transaction(function () use (
            $currentOffice,
            $definition,
            $name,
            $departmentId,
            $rules,
        ): ProcessTemplate {
            $template = ProcessTemplate::query()->create([
                'office_id' => $currentOffice->id(),
                'catalog_key' => $definition['key'],
                'catalog_version' => $definition['version'],
                'name' => $name,
                'description' => $definition['description'],
                'monitoring_module_key' => $definition['monitoring_module_key'],
                'audience_rules' => $rules,
                'default_department_id' => $departmentId,
                'default_due_rule_type' => $definition['default_due_rule_type'],
                'default_due_rule_value' => $definition['default_due_rule_value'],
                'is_active' => true,
                'lock_version' => 1,
                'created_by_membership_id' => $currentOffice->membership()?->id,
            ]);

            foreach ($definition['tasks'] as $task) {
                ProcessTemplateTask::query()->create([
                    'office_id' => $currentOffice->id(),
                    'process_template_id' => $template->id,
                    'sort_order' => $task['sort_order'],
                    'title' => $task['title'],
                    'description' => $task['description'],
                    'due_rule_type' => $task['due_rule_type'],
                    'due_rule_value' => $task['due_rule_value'],
                    'default_department_id' => $departmentId,
                    'default_assignee_membership_id' => null,
                    'is_required' => $task['is_required'],
                    'is_critical' => $task['is_critical'],
                    'requires_evidence' => $task['requires_evidence'],
                ]);
            }

            return $template->load('tasks');
        });

        $audit->record('work.template_catalog.install', 'SUCCESS', $template, [
            'catalog_key' => $definition['key'],
            'catalog_version' => $definition['version'],
        ]);

        return response()->json([
            'data' => [
                'id' => $template->id,
                'catalog_key' => $template->catalog_key,
                'catalog_version' => $template->catalog_version,
                'name' => $template->name,
                'monitoring_module_key' => $template->monitoring_module_key,
                'audience_rules' => $template->audience_rules,
                'default_department_id' => $template->default_department_id,
                'is_active' => $template->is_active,
                'lock_version' => $template->lock_version,
                'tasks' => $template->tasks->map(static fn (ProcessTemplateTask $task): array => [
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
            ],
        ], 201);
    }

    private function departmentForRole(int $officeId, ?string $role): ?int
    {
        if ($role === null || trim($role) === '') {
            return null;
        }

        return WorkDepartment::query()
            ->where('office_id', $officeId)
            ->where('is_active', true)
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper($role)])
            ->value('id');
    }

    private function uniqueName(int $officeId, string $requested): string
    {
        $base = $requested !== '' ? $requested : 'Modelo de processo';
        $candidate = $base;
        $suffix = 2;
        while (ProcessTemplate::query()
            ->where('office_id', $officeId)
            ->where('name', $candidate)
            ->exists()) {
            $candidate = mb_substr($base, 0, 150).' ('.$suffix.')';
            $suffix++;
        }

        return $candidate;
    }
}

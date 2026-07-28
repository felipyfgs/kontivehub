<?php

namespace App\Actions\Work;

use App\DTO\Work\WorkProcessTemplateCatalogInstallationData;
use App\Models\WorkDepartment;
use App\Models\WorkProcessTemplate;
use App\Models\WorkProcessTemplateTask;
use App\Services\Audit\AuditLogger;
use App\Services\Work\MembershipResolver;
use App\Services\Work\ProcessAudienceResolver;
use App\Services\Work\WorkMonitoringContextRegistry;
use App\Services\Work\WorkProcessTemplateCatalog;
use App\Support\CurrentTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InstallWorkProcessTemplateCatalogAction
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly WorkProcessTemplateCatalog $catalog,
        private readonly ProcessAudienceResolver $audiences,
        private readonly WorkMonitoringContextRegistry $monitoring,
        private readonly MembershipResolver $memberships,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(
        string $catalogKey,
        WorkProcessTemplateCatalogInstallationData $data,
    ): WorkProcessTemplate {
        try {
            $definition = $this->catalog->findOrFail($catalogKey);
        } catch (InvalidArgumentException) {
            throw new NotFoundHttpException;
        }

        if (! $this->monitoring->allows($definition['monitoring_module_key'])) {
            throw ValidationException::withMessages([
                'catalog_key' => ['Modelo-base possui contexto de Monitoramento inválido.'],
            ]);
        }

        $tenantId = (int) $this->currentTenant->id();
        $rules = $this->audiences->normalizeRules($definition['audience_rules']);

        try {
            $template = DB::transaction(function () use (
                $tenantId,
                $definition,
                $data,
                $rules,
            ): WorkProcessTemplate {
                $existing = WorkProcessTemplate::query()
                    ->where('tenant_id', $tenantId)
                    ->where('catalog_key', $definition['key'])
                    ->first();
                if ($existing !== null) {
                    throw ValidationException::withMessages([
                        'catalog_key' => ['Este modelo-base já está instalado no escritório.'],
                    ]);
                }

                $departmentId = $data->defaultDepartmentId
                    ?? $this->departmentForRole($tenantId, $definition['department_role']);
                if ($departmentId !== null) {
                    $this->memberships->requireActiveDepartment($departmentId);
                }

                $requestedName = trim((string) ($data->name ?? $definition['name']));
                $name = $this->uniqueName($tenantId, $requestedName);

                $template = WorkProcessTemplate::query()->create([
                    'tenant_id' => $tenantId,
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
                    'created_by_membership_id' => $this->currentTenant->realMembership()?->id,
                ]);

                foreach ($definition['tasks'] as $task) {
                    WorkProcessTemplateTask::query()->create([
                        'tenant_id' => $tenantId,
                        'work_process_template_id' => $template->id,
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
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'catalog_key' => ['Este modelo-base já está instalado no escritório.'],
            ]);
        }

        $this->audit->record('work.template_catalog.install', 'SUCCESS', $template, [
            'catalog_key' => $definition['key'],
            'catalog_version' => $definition['version'],
        ]);

        return $template;
    }

    private function departmentForRole(int $tenantId, ?string $role): ?int
    {
        if ($role === null || trim($role) === '') {
            return null;
        }

        return WorkDepartment::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereRaw('UPPER(code) = ?', [mb_strtoupper($role)])
            ->value('id');
    }

    private function uniqueName(int $tenantId, string $requested): string
    {
        $base = $requested !== '' ? $requested : 'Modelo de processo';
        $candidate = $base;
        $suffix = 2;

        while (WorkProcessTemplate::query()
            ->where('tenant_id', $tenantId)
            ->where('name', $candidate)
            ->exists()) {
            $candidate = mb_substr($base, 0, 150).' ('.$suffix.')';
            $suffix++;
        }

        return $candidate;
    }
}

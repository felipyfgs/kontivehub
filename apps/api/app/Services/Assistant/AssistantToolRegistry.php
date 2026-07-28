<?php

namespace App\Services\Assistant;

use App\Exceptions\AssistantToolNotAllowedException;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Models\WorkProcessTemplate;
use App\Services\Work\WorkMonitoringContextRegistry;
use App\Services\Work\WorkProcessTemplateWriter;
use App\Support\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;

/**
 * Allowlist server-side de tools do assistente.
 */
final class AssistantToolRegistry
{
    public const LIST_PROCESS_TEMPLATES = 'list_work_process_templates';

    public const LIST_WORK_DEPARTMENTS = 'list_work_departments';

    public const LIST_MONITORING_MODULES = 'list_monitoring_modules';

    public const CREATE_PROCESS_TEMPLATE = 'create_process_template';

    /** @var list<string> */
    public const ALLOWLIST = [
        self::LIST_PROCESS_TEMPLATES,
        self::LIST_WORK_DEPARTMENTS,
        self::LIST_MONITORING_MODULES,
        self::CREATE_PROCESS_TEMPLATE,
    ];

    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly WorkMonitoringContextRegistry $monitoringContexts,
        private readonly WorkProcessTemplateWriter $templateWriter,
        private readonly AssistantPendingApprovalStore $approvals,
    ) {}

    public function isAllowlisted(string $name): bool
    {
        return in_array($name, self::ALLOWLIST, true);
    }

    /**
     * Schemas OpenAI function-calling.
     *
     * @return list<array<string, mixed>>
     */
    public function openAiTools(): array
    {
        return [
            $this->functionTool(self::LIST_PROCESS_TEMPLATES, 'Lista modelos de processo do escritório atual.', [
                'type' => 'object',
                'properties' => [
                    'q' => ['type' => 'string', 'description' => 'Filtro opcional por nome/descrição'],
                    'is_active' => ['type' => 'boolean'],
                ],
            ]),
            $this->functionTool(self::LIST_WORK_DEPARTMENTS, 'Lista departamentos Work do escritório atual.', [
                'type' => 'object',
                'properties' => [
                    'is_active' => ['type' => 'boolean'],
                ],
            ]),
            $this->functionTool(self::LIST_MONITORING_MODULES, 'Lista chaves allowlisted de módulos de Monitoramento.', [
                'type' => 'object',
                'properties' => (object) [],
            ]),
            $this->functionTool(self::CREATE_PROCESS_TEMPLATE, 'Propõe criação de modelo de processo. Requer confirmação explícita do usuário e permissão work.catalog.manage.', [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'monitoring_module_key' => ['type' => 'string'],
                    'default_department_id' => ['type' => 'integer'],
                    'default_due_rule_type' => ['type' => 'string'],
                    'default_due_rule_value' => ['type' => 'integer'],
                    'is_active' => ['type' => 'boolean'],
                    'audience_rules' => ['type' => 'object'],
                    'tasks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'sort_order' => ['type' => 'integer'],
                                'title' => ['type' => 'string'],
                                'description' => ['type' => 'string'],
                                'due_rule_type' => ['type' => 'string'],
                                'due_rule_value' => ['type' => 'integer'],
                                'default_department_id' => ['type' => 'integer'],
                                'default_assignee_membership_id' => ['type' => 'integer'],
                                'is_required' => ['type' => 'boolean'],
                                'is_critical' => ['type' => 'boolean'],
                                'requires_evidence' => ['type' => 'boolean'],
                            ],
                            'required' => ['sort_order', 'title'],
                        ],
                    ],
                ],
                'required' => ['name'],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{status: string, result?: mixed, approval_token?: string, tool_name?: string, args?: array<string, mixed>, error?: string}
     */
    public function execute(
        string $name,
        array $arguments,
        ?User $user = null,
        bool $approved = false,
        ?string $approvalToken = null,
        ?int $conversationId = null,
        ?string $toolCallId = null,
    ): array {
        if (! $this->isAllowlisted($name)) {
            throw new AssistantToolNotAllowedException;
        }

        $tenantId = $this->currentTenant->id();
        if ($tenantId === null) {
            abort(404);
        }

        return match ($name) {
            self::LIST_PROCESS_TEMPLATES => [
                'status' => 'ok',
                'result' => $this->listWorkProcessTemplates($arguments, $user),
            ],
            self::LIST_WORK_DEPARTMENTS => [
                'status' => 'ok',
                'result' => $this->listWorkDepartments($arguments, $user),
            ],
            self::LIST_MONITORING_MODULES => [
                'status' => 'ok',
                'result' => $this->listMonitoringModules($user),
            ],
            self::CREATE_PROCESS_TEMPLATE => $this->createWorkProcessTemplate(
                $arguments,
                $user,
                $approved,
                $approvalToken,
                $conversationId,
                $toolCallId,
            ),
            default => throw new AssistantToolNotAllowedException,
        };
    }

    /**
     * Leitura Work: viewAny WorkProcessTemplate (work.view).
     *
     * @throws AuthorizationException
     */
    private function assertWorkView(?User $user): void
    {
        if ($user === null) {
            throw new AuthorizationException;
        }

        Gate::forUser($user)->authorize('viewAny', WorkProcessTemplate::class);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{status: string, result?: mixed, approval_token?: string, tool_name?: string, args?: array<string, mixed>, error?: string}
     */
    private function createWorkProcessTemplate(
        array $arguments,
        ?User $user,
        bool $approved,
        ?string $approvalToken,
        ?int $conversationId,
        ?string $toolCallId,
    ): array {
        if (! $approved) {
            if ($conversationId === null) {
                return [
                    'status' => 'pending_approval',
                    'tool_name' => self::CREATE_PROCESS_TEMPLATE,
                    'args' => $arguments,
                    'error' => 'APPROVAL_REQUIRED',
                ];
            }

            $token = $this->approvals->put(
                (int) $this->currentTenant->id(),
                $conversationId,
                $toolCallId ?? ('call_'.bin2hex(random_bytes(8))),
                self::CREATE_PROCESS_TEMPLATE,
                $arguments,
            );

            return [
                'status' => 'pending_approval',
                'approval_token' => $token,
                'tool_name' => self::CREATE_PROCESS_TEMPLATE,
                'args' => $arguments,
            ];
        }

        if ($user === null) {
            throw new AuthorizationException;
        }

        if (! Gate::forUser($user)->allows('create', WorkProcessTemplate::class)) {
            throw new AuthorizationException;
        }

        if ($approvalToken === null || $conversationId === null) {
            return [
                'status' => 'rejected',
                'error' => 'APPROVAL_REQUIRED',
            ];
        }

        $pending = $this->approvals->pull(
            $approvalToken,
            (int) $this->currentTenant->id(),
            $conversationId,
        );

        if ($pending === null || ($pending['tool_name'] ?? null) !== self::CREATE_PROCESS_TEMPLATE) {
            return [
                'status' => 'rejected',
                'error' => 'APPROVAL_INVALID',
            ];
        }

        /** @var array<string, mixed> $args */
        $args = is_array($pending['args'] ?? null) ? $pending['args'] : $arguments;
        $template = $this->templateWriter->create($args);

        return [
            'status' => 'ok',
            'result' => $this->templateWriter->toPublic($template),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    private function listWorkProcessTemplates(array $arguments, ?User $user): array
    {
        $this->assertWorkView($user);

        $q = WorkProcessTemplate::query()
            ->with('tasks')
            ->where('tenant_id', $this->currentTenant->id())
            ->orderBy('name')
            ->limit(50);

        if (array_key_exists('is_active', $arguments)) {
            $q->where('is_active', (bool) $arguments['is_active']);
        }
        if (! empty($arguments['q']) && is_string($arguments['q'])) {
            $needle = '%'.mb_strtolower($arguments['q']).'%';
            $q->where(function ($search) use ($needle): void {
                $search->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$needle]);
            });
        }

        return $q->get()->map(fn (WorkProcessTemplate $t) => $this->templateWriter->toPublic($t))->values()->all();
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array{id: int, name: string, code: string, is_active: bool}>
     */
    private function listWorkDepartments(array $arguments, ?User $user): array
    {
        $this->assertWorkView($user);

        $q = WorkDepartment::query()
            ->where('tenant_id', $this->currentTenant->id())
            ->orderBy('name')
            ->limit(100);

        if (array_key_exists('is_active', $arguments)) {
            $q->where('is_active', (bool) $arguments['is_active']);
        }

        return $q->get()->map(fn (WorkDepartment $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'code' => $d->code,
            'is_active' => $d->is_active,
        ])->values()->all();
    }

    /**
     * @return list<array{key: string}>
     */
    private function listMonitoringModules(?User $user): array
    {
        $this->assertWorkView($user);

        return collect($this->monitoringContexts->keys())
            ->map(fn (string $key) => ['key' => $key])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array<string, mixed>
     */
    private function functionTool(string $name, string $description, array $parameters): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
            ],
        ];
    }
}

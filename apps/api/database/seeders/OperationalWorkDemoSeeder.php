<?php

namespace Database\Seeders;

use App\Contracts\SecureObjectStore;
use App\Domain\Work\ReferencePeriod;
use App\Enums\Work\DueRuleType;
use App\Enums\Work\ProcessOrigin;
use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;
use App\Models\Client;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\OperationalComment;
use App\Models\OperationalProcess;
use App\Models\OperationalTask;
use App\Models\OperationalTaskEvidence;
use App\Models\ProcessTemplate;
use App\Models\ProcessTemplateTask;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Services\Work\Demo\WorkDemoAnchor;
use App\Services\Work\Demo\WorkDemoEnvironmentGuard;
use App\Services\Work\OperationalEvidenceService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Massa operacional demonstrativa para o office `demo` (local/testing).
 *
 * - Fail-closed fora de local/testing
 * - Âncora temporal controlável (DEMO_WORK_ANCHOR_DATE)
 * - Reconciliação por chaves lógicas estáveis + marker
 * - Office sentinela para isolamento multi-tenant
 * - Sem segredos, XML fiscal real ou credenciais SERPRO
 */
class OperationalWorkDemoSeeder extends Seeder
{
    private WorkDemoEnvironmentGuard $guard;

    private CarbonImmutable $anchor;

    private string $marker;

    private string $watermark;

    /** @var array<string, int> */
    private array $counts = [
        'departments' => 0,
        'clients' => 0,
        'templates' => 0,
        'processes' => 0,
        'tasks' => 0,
        'comments' => 0,
        'evidences' => 0,
        'sentinel_processes' => 0,
        'sentinel_tasks' => 0,
    ];

    public function run(): void
    {
        $this->guard = app(WorkDemoEnvironmentGuard::class);

        // Abort ANTES de qualquer escrita.
        $office = $this->guard->assertCanSeed();
        $this->marker = $this->guard->fixtureMarker();
        $this->watermark = $this->guard->watermark();
        $this->anchor = app(WorkDemoAnchor::class)->resolve($office);

        DB::transaction(function () use ($office): void {
            $memberships = $this->resolveDemoMemberships($office);
            $departments = $this->reconcileDepartments($office);
            $this->assignMembershipDepartments($memberships, $departments);

            $clients = $this->reconcileClients($office);
            $templates = $this->reconcileTemplates($office, $departments, $memberships['admin']);
            $this->reconcileProcessesAndTasks($office, $clients, $departments, $templates, $memberships);

            $sentinel = $this->reconcileSentinelOffice($clients, $departments);
            $this->seedSentinelVisibleData($sentinel, $clients);
        });

        $this->emitSummary($office);
    }

    /**
     * @return array{admin: OfficeMembership, operator: OfficeMembership, viewer: OfficeMembership}
     */
    private function resolveDemoMemberships(Office $office): array
    {
        $map = [
            'admin' => 'admin@example.com',
            'operator' => 'operador@example.com',
            'viewer' => 'viewer@example.com',
        ];

        $out = [];
        foreach ($map as $key => $email) {
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                throw new \RuntimeException(
                    "Usuário demo \"{$email}\" ausente. Execute o DatabaseSeeder base antes."
                );
            }

            $membership = OfficeMembership::query()
                ->where('office_id', $office->id)
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->first();

            if ($membership === null) {
                throw new \RuntimeException(
                    "Membership ativa de \"{$email}\" no office demo ausente."
                );
            }

            $out[$key] = $membership;
        }

        return $out;
    }

    /**
     * @return array<string, WorkDepartment>
     */
    private function reconcileDepartments(Office $office): array
    {
        $defs = [
            'fiscal' => ['code' => 'FIS', 'name' => 'Fiscal', 'color' => '#2563eb'],
            'pessoal' => ['code' => 'PES', 'name' => 'Pessoal', 'color' => '#16a34a'],
            'contabil' => ['code' => 'CON', 'name' => 'Contábil', 'color' => '#9333ea'],
            'societario' => ['code' => 'SOC', 'name' => 'Societário', 'color' => '#ea580c'],
        ];

        $result = [];
        foreach ($defs as $key => $def) {
            $dept = WorkDepartment::query()->updateOrCreate(
                ['office_id' => $office->id, 'code' => $def['code']],
                [
                    'name' => $def['name'],
                    'color' => $def['color'],
                    'is_active' => true,
                ],
            );
            $result[$key] = $dept;
            $this->counts['departments']++;
        }

        return $result;
    }

    /**
     * @param  array{admin: OfficeMembership, operator: OfficeMembership, viewer: OfficeMembership}  $memberships
     * @param  array<string, WorkDepartment>  $departments
     */
    private function assignMembershipDepartments(array $memberships, array $departments): void
    {
        // Admin sem dept fixo (visão ampla); operador no Fiscal; viewer no Contábil (só leitura).
        $memberships['operator']->forceFill([
            'work_department_id' => $departments['fiscal']->id,
        ])->save();

        $memberships['viewer']->forceFill([
            'work_department_id' => $departments['contabil']->id,
        ])->save();

        $memberships['admin']->forceFill([
            'work_department_id' => null,
        ])->save();
    }

    /**
     * Clientes sintéticos com root_cnpj estável (8 chars) e nomes explicitamente demo.
     *
     * @return array<string, Client>
     */
    private function reconcileClients(Office $office): array
    {
        // Roots numéricos determinísticos (8 chars) — não são documentos fiscais reais.
        $defs = [
            'alpha' => ['root' => '90001001', 'legal' => 'DEMO Alpha Serviços Contábeis LTDA', 'display' => '[DEMO] Alpha Serviços'],
            'beta' => ['root' => '90001002', 'legal' => 'DEMO Beta Comércio de Peças LTDA', 'display' => '[DEMO] Beta Comércio'],
            'gamma' => ['root' => '90001003', 'legal' => 'DEMO Gamma Indústria ME', 'display' => '[DEMO] Gamma Indústria'],
            'delta' => ['root' => '90001004', 'legal' => 'DEMO Delta Consultoria LTDA', 'display' => '[DEMO] Delta Consultoria'],
            'epsilon' => ['root' => '90001005', 'legal' => 'DEMO Epsilon Transportes LTDA', 'display' => '[DEMO] Epsilon Transportes'],
            'shared' => ['root' => '90001999', 'legal' => 'DEMO Cliente Compartilhado Isolamento LTDA', 'display' => '[DEMO] Cliente Isolamento'],
        ];

        $result = [];
        foreach ($defs as $key => $def) {
            $client = Client::query()->updateOrCreate(
                ['office_id' => $office->id, 'root_cnpj' => $def['root']],
                [
                    'legal_name' => $def['legal'],
                    'display_name' => $def['display'],
                    'is_active' => true,
                    'notes' => $this->marker.' client.'.$key,
                ],
            );
            $result[$key] = $client;
            $this->counts['clients']++;
        }

        return $result;
    }

    /**
     * @param  array<string, WorkDepartment>  $departments
     * @return array<string, ProcessTemplate>
     */
    private function reconcileTemplates(Office $office, array $departments, OfficeMembership $admin): array
    {
        $defs = [
            'das' => [
                'name' => 'DEMO · DAS mensal',
                'description' => $this->marker.' template.das',
                'dept' => 'fiscal',
                'due_type' => DueRuleType::FixedDayOfCompetence,
                'due_value' => 20,
                'tasks' => [
                    ['title' => 'Apurar DAS', 'days_before' => 5, 'critical' => true, 'evidence' => false],
                    ['title' => 'Transmitir DAS', 'days_before' => 1, 'critical' => true, 'evidence' => true],
                    ['title' => 'Arquivar comprovante', 'days_before' => 0, 'critical' => false, 'evidence' => true],
                ],
            ],
            'folha' => [
                'name' => 'DEMO · Folha e encargos',
                'description' => $this->marker.' template.folha',
                'dept' => 'pessoal',
                'due_type' => DueRuleType::FixedDayOfCompetence,
                'due_value' => 7,
                'tasks' => [
                    ['title' => 'Conferir ponto', 'days_before' => 4, 'critical' => false, 'evidence' => false],
                    ['title' => 'Calcular folha', 'days_before' => 2, 'critical' => true, 'evidence' => false],
                    ['title' => 'Enviar eSocial', 'days_before' => 0, 'critical' => true, 'evidence' => true],
                ],
            ],
            'balancete' => [
                'name' => 'DEMO · Balancete mensal',
                'description' => $this->marker.' template.balancete',
                'dept' => 'contabil',
                'due_type' => DueRuleType::DaysAfterCompetenceStart,
                'due_value' => 15,
                'tasks' => [
                    ['title' => 'Importar extratos', 'days_before' => 7, 'critical' => false, 'evidence' => false],
                    ['title' => 'Conciliar contas', 'days_before' => 3, 'critical' => true, 'evidence' => false],
                    ['title' => 'Emitir balancete', 'days_before' => 0, 'critical' => true, 'evidence' => true],
                ],
            ],
            'alteracao' => [
                'name' => 'DEMO · Alteração contratual',
                'description' => $this->marker.' template.alteracao',
                'dept' => 'societario',
                'due_type' => DueRuleType::FixedDayOfCompetence,
                'due_value' => 28,
                'tasks' => [
                    ['title' => 'Coletar documentos', 'days_before' => 10, 'critical' => false, 'evidence' => true],
                    ['title' => 'Elaborar minutas', 'days_before' => 5, 'critical' => true, 'evidence' => false],
                    ['title' => 'Protocolar Junta', 'days_before' => 0, 'critical' => true, 'evidence' => true],
                ],
            ],
        ];

        $result = [];
        foreach ($defs as $key => $def) {
            $dept = $departments[$def['dept']];
            $template = ProcessTemplate::query()->updateOrCreate(
                ['office_id' => $office->id, 'name' => $def['name']],
                [
                    'description' => $def['description'],
                    'default_department_id' => $dept->id,
                    'default_due_rule_type' => $def['due_type'],
                    'default_due_rule_value' => $def['due_value'],
                    'is_active' => true,
                    'lock_version' => 1,
                    'created_by_membership_id' => $admin->id,
                ],
            );

            foreach ($def['tasks'] as $i => $taskDef) {
                ProcessTemplateTask::query()->updateOrCreate(
                    [
                        'office_id' => $office->id,
                        'process_template_id' => $template->id,
                        'sort_order' => $i + 1,
                    ],
                    [
                        'title' => $taskDef['title'],
                        'description' => $this->marker.' template_task.'.$key.'.'.($i + 1),
                        'due_rule_type' => DueRuleType::DaysBeforeProcessDue,
                        'due_rule_value' => $taskDef['days_before'],
                        'default_department_id' => $dept->id,
                        'is_required' => true,
                        'is_critical' => $taskDef['critical'],
                        'requires_evidence' => $taskDef['evidence'],
                    ],
                );
            }

            $result[$key] = $template->fresh(['tasks']);
            $this->counts['templates']++;
        }

        return $result;
    }

    /**
     * @param  array<string, Client>  $clients
     * @param  array<string, WorkDepartment>  $departments
     * @param  array<string, ProcessTemplate>  $templates
     * @param  array{admin: OfficeMembership, operator: OfficeMembership, viewer: OfficeMembership}  $memberships
     */
    private function reconcileProcessesAndTasks(
        Office $office,
        array $clients,
        array $departments,
        array $templates,
        array $memberships,
    ): void {
        $prev = $this->anchor->subMonthNoOverflow()->format('Y-m');
        $curr = $this->anchor->format('Y-m');
        $next = $this->anchor->addMonthNoOverflow()->format('Y-m');

        $scenarios = [
            // Competência anterior — mix de concluídos e atrasados
            [
                'key' => 'das.alpha.prev',
                'client' => 'alpha',
                'template' => 'das',
                'dept' => 'fiscal',
                'competence' => $prev,
                'due_offset' => -25,
                'fine' => true,
                'status' => ProcessStatus::EmProgresso,
                'assignee' => 'operator',
                'tasks' => [
                    ['title' => 'Apurar DAS', 'status' => TaskStatus::Concluida, 'due' => -30, 'assignee' => 'operator', 'critical' => true, 'evidence' => false],
                    ['title' => 'Transmitir DAS', 'status' => TaskStatus::Impedida, 'due' => -26, 'assignee' => 'operator', 'critical' => true, 'evidence' => true, 'block' => 'Aguardando senha do portal do cliente (DEMO)'],
                    ['title' => 'Arquivar comprovante', 'status' => TaskStatus::AFazer, 'due' => -25, 'assignee' => 'operator', 'critical' => false, 'evidence' => true],
                ],
            ],
            [
                'key' => 'folha.beta.prev',
                'client' => 'beta',
                'template' => 'folha',
                'dept' => 'pessoal',
                'competence' => $prev,
                'due_offset' => -20,
                'fine' => false,
                'status' => ProcessStatus::Concluido,
                'assignee' => 'admin',
                'tasks' => [
                    ['title' => 'Conferir ponto', 'status' => TaskStatus::Concluida, 'due' => -24, 'assignee' => 'admin', 'critical' => false, 'evidence' => false],
                    ['title' => 'Calcular folha', 'status' => TaskStatus::Concluida, 'due' => -22, 'assignee' => 'admin', 'critical' => true, 'evidence' => false],
                    ['title' => 'Enviar eSocial', 'status' => TaskStatus::Dispensada, 'due' => -20, 'assignee' => 'admin', 'critical' => true, 'evidence' => true],
                ],
            ],
            // Competência atual — vence hoje, próximos, sem prazo, sem responsável
            [
                'key' => 'das.gamma.curr',
                'client' => 'gamma',
                'template' => 'das',
                'dept' => 'fiscal',
                'competence' => $curr,
                'due_offset' => 0,
                'fine' => true,
                'status' => ProcessStatus::EmProgresso,
                'assignee' => 'operator',
                'tasks' => [
                    ['title' => 'Apurar DAS', 'status' => TaskStatus::EmProgresso, 'due' => -2, 'assignee' => 'operator', 'critical' => true, 'evidence' => false],
                    ['title' => 'Transmitir DAS', 'status' => TaskStatus::AFazer, 'due' => 0, 'assignee' => 'operator', 'critical' => true, 'evidence' => true],
                    ['title' => 'Arquivar comprovante', 'status' => TaskStatus::AFazer, 'due' => 1, 'assignee' => null, 'critical' => false, 'evidence' => true],
                ],
            ],
            [
                'key' => 'balancete.delta.curr',
                'client' => 'delta',
                'template' => 'balancete',
                'dept' => 'contabil',
                'competence' => $curr,
                'due_offset' => 5,
                'fine' => false,
                'status' => ProcessStatus::AFazer,
                'assignee' => null,
                'tasks' => [
                    ['title' => 'Importar extratos', 'status' => TaskStatus::AFazer, 'due' => 2, 'assignee' => null, 'critical' => false, 'evidence' => false],
                    ['title' => 'Conciliar contas', 'status' => TaskStatus::AFazer, 'due' => 4, 'assignee' => null, 'critical' => true, 'evidence' => false],
                    ['title' => 'Emitir balancete', 'status' => TaskStatus::AFazer, 'due' => 5, 'assignee' => null, 'critical' => true, 'evidence' => true],
                ],
            ],
            [
                'key' => 'alteracao.epsilon.curr',
                'client' => 'epsilon',
                'template' => 'alteracao',
                'dept' => 'societario',
                'competence' => $curr,
                'due_offset' => 12,
                'fine' => false,
                'status' => ProcessStatus::EmProgresso,
                'assignee' => 'admin',
                'tasks' => [
                    ['title' => 'Coletar documentos', 'status' => TaskStatus::Concluida, 'due' => -1, 'assignee' => 'admin', 'critical' => false, 'evidence' => true, 'with_evidence' => true],
                    ['title' => 'Elaborar minutas', 'status' => TaskStatus::EmProgresso, 'due' => 7, 'assignee' => 'admin', 'critical' => true, 'evidence' => false],
                    ['title' => 'Protocolar Junta', 'status' => TaskStatus::AFazer, 'due' => null, 'assignee' => 'admin', 'critical' => true, 'evidence' => true],
                ],
            ],
            // Competência seguinte — planejado
            [
                'key' => 'das.alpha.next',
                'client' => 'alpha',
                'template' => 'das',
                'dept' => 'fiscal',
                'competence' => $next,
                'due_offset' => 28,
                'fine' => true,
                'status' => ProcessStatus::AFazer,
                'assignee' => 'operator',
                'tasks' => [
                    ['title' => 'Apurar DAS', 'status' => TaskStatus::AFazer, 'due' => 23, 'assignee' => 'operator', 'critical' => true, 'evidence' => false],
                    ['title' => 'Transmitir DAS', 'status' => TaskStatus::AFazer, 'due' => 27, 'assignee' => 'operator', 'critical' => true, 'evidence' => true],
                    ['title' => 'Arquivar comprovante', 'status' => TaskStatus::AFazer, 'due' => 28, 'assignee' => 'operator', 'critical' => false, 'evidence' => true],
                ],
            ],
            [
                'key' => 'folha.shared.curr',
                'client' => 'shared',
                'template' => 'folha',
                'dept' => 'pessoal',
                'competence' => $curr,
                'due_offset' => 3,
                'fine' => true,
                'status' => ProcessStatus::EmProgresso,
                'assignee' => 'operator',
                'tasks' => [
                    ['title' => 'Conferir ponto', 'status' => TaskStatus::EmProgresso, 'due' => 1, 'assignee' => 'operator', 'critical' => false, 'evidence' => false],
                    ['title' => 'Calcular folha', 'status' => TaskStatus::AFazer, 'due' => 2, 'assignee' => 'operator', 'critical' => true, 'evidence' => false],
                    ['title' => 'Enviar eSocial', 'status' => TaskStatus::AFazer, 'due' => 3, 'assignee' => 'operator', 'critical' => true, 'evidence' => true],
                ],
            ],
        ];

        foreach ($scenarios as $scenario) {
            $this->seedScenario($office, $scenario, $clients, $departments, $templates, $memberships);
        }
    }

    /**
     * @param  array<string, mixed>  $scenario
     * @param  array<string, Client>  $clients
     * @param  array<string, WorkDepartment>  $departments
     * @param  array<string, ProcessTemplate>  $templates
     * @param  array{admin: OfficeMembership, operator: OfficeMembership, viewer: OfficeMembership}  $memberships
     */
    private function seedScenario(
        Office $office,
        array $scenario,
        array $clients,
        array $departments,
        array $templates,
        array $memberships,
    ): void {
        $client = $clients[$scenario['client']];
        $dept = $departments[$scenario['dept']];
        $template = $templates[$scenario['template']];
        $assigneeId = $scenario['assignee'] !== null
            ? $memberships[$scenario['assignee']]->id
            : null;

        $dueDate = $this->anchor->addDays((int) $scenario['due_offset'])->toDateString();
        $title = 'DEMO · '.$scenario['key'];
        $periodAttrs = $this->periodAttributes((string) $scenario['competence']);

        $process = OperationalProcess::query()->updateOrCreate(
            [
                'office_id' => $office->id,
                'title' => $title,
            ],
            [
                'client_id' => $client->id,
                'process_template_id' => $template->id,
                'origin' => ProcessOrigin::Template,
                'description' => $this->marker.' process.'.$scenario['key'],
                ...$periodAttrs,
                'due_date' => $dueDate,
                'target_due_date' => $dueDate,
                'subject_to_fine' => (bool) $scenario['fine'],
                'work_department_id' => $dept->id,
                'assignee_membership_id' => $assigneeId,
                'status' => $scenario['status'],
                'lock_version' => 1,
                'created_by_membership_id' => $memberships['admin']->id,
                'started_at' => in_array($scenario['status'], [ProcessStatus::EmProgresso, ProcessStatus::Concluido], true)
                    ? $this->anchor->subDays(3)
                    : null,
                'completed_at' => $scenario['status'] === ProcessStatus::Concluido
                    ? $this->anchor->subDays(1)
                    : null,
            ],
        );
        $this->counts['processes']++;

        foreach ($scenario['tasks'] as $i => $taskDef) {
            $taskDue = array_key_exists('due', $taskDef) && $taskDef['due'] !== null
                ? $this->anchor->addDays((int) $taskDef['due'])->toDateString()
                : null;

            $taskAssignee = array_key_exists('assignee', $taskDef) && $taskDef['assignee'] !== null
                ? $memberships[$taskDef['assignee']]->id
                : null;

            $status = $taskDef['status'];
            $task = OperationalTask::query()->updateOrCreate(
                [
                    'office_id' => $office->id,
                    'operational_process_id' => $process->id,
                    'sort_order' => $i + 1,
                ],
                [
                    'title' => $taskDef['title'],
                    'description' => $this->marker.' task.'.$scenario['key'].'.'.($i + 1),
                    'status' => $status,
                    'due_date' => $taskDue,
                    'target_due_date' => $taskDue,
                    'work_department_id' => $dept->id,
                    'assignee_membership_id' => $taskAssignee,
                    'is_required' => true,
                    'is_critical' => (bool) ($taskDef['critical'] ?? false),
                    'requires_evidence' => (bool) ($taskDef['evidence'] ?? false),
                    'block_reason' => $taskDef['block'] ?? null,
                    'lock_version' => 1,
                    'started_at' => in_array($status, [TaskStatus::EmProgresso, TaskStatus::Concluida, TaskStatus::Impedida], true)
                        ? $this->anchor->subDays(2)
                        : null,
                    'completed_at' => in_array($status, [TaskStatus::Concluida, TaskStatus::Dispensada], true)
                        ? $this->anchor->subDay()
                        : null,
                    'started_by_membership_id' => $taskAssignee,
                    'completed_by_membership_id' => in_array($status, [TaskStatus::Concluida, TaskStatus::Dispensada], true)
                        ? $taskAssignee
                        : null,
                ],
            );
            $this->counts['tasks']++;

            // Comentário sintético em tarefas abertas ou impedidas
            if (in_array($status, [TaskStatus::EmProgresso, TaskStatus::Impedida, TaskStatus::AFazer], true) && $i === 0) {
                $body = $this->marker.' Comentário demo em '.$task->title.' — sem payload externo.';
                $exists = OperationalComment::query()
                    ->where('office_id', $office->id)
                    ->where('operational_task_id', $task->id)
                    ->where('body', $body)
                    ->exists();
                if (! $exists) {
                    OperationalComment::query()->create([
                        'office_id' => $office->id,
                        'operational_process_id' => $process->id,
                        'operational_task_id' => $task->id,
                        'author_membership_id' => $memberships['operator']->id,
                        'body' => $body,
                    ]);
                    $this->counts['comments']++;
                }
            }

            if (! empty($taskDef['with_evidence'])) {
                $this->ensureSyntheticEvidence($office, $task, $memberships['admin']);
            }
        }
    }

    private function ensureSyntheticEvidence(Office $office, OperationalTask $task, OfficeMembership $uploader): void
    {
        $filename = 'demo-work-evidence-'.$task->id.'.txt';
        $existing = OperationalTaskEvidence::query()
            ->where('office_id', $office->id)
            ->where('operational_task_id', $task->id)
            ->where('original_filename', $filename)
            ->whereNull('removed_at')
            ->first();

        if ($existing !== null) {
            $this->counts['evidences']++;

            return;
        }

        $bytes = $this->watermark."\n"
            .$this->marker."\n"
            ."Evidência textual sintética da tarefa #{$task->id}.\n"
            ."Âncora: {$this->anchor->toDateString()}.\n"
            ."SEM VALIDADE FISCAL — uso exclusivo de demonstração local/testing.\n";

        $sha256 = hash('sha256', $bytes);
        $store = app(SecureObjectStore::class);

        $evidence = OperationalTaskEvidence::query()->create([
            'office_id' => $office->id,
            'operational_task_id' => $task->id,
            'original_filename' => $filename,
            'mime_type' => 'text/plain',
            'byte_size' => strlen($bytes),
            'sha256' => $sha256,
            'vault_object_id' => (string) Str::uuid(),
            'uploaded_by_membership_id' => $uploader->id,
        ]);

        $aad = OperationalEvidenceService::aad(
            (int) $office->id,
            (int) $task->id,
            (string) $evidence->id,
            $sha256,
        );
        $objectId = $store->put($bytes, $aad);
        $evidence->forceFill(['vault_object_id' => $objectId])->save();

        $this->counts['evidences']++;
    }

    /**
     * @param  array<string, Client>  $demoClients
     * @param  array<string, WorkDepartment>  $demoDepts  unused shape reference
     */
    private function reconcileSentinelOffice(array $demoClients, array $demoDepts): Office
    {
        $slug = $this->guard->sentinelOfficeSlug();
        $sentinel = Office::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => 'Demo Work Sentinel',
                'is_active' => true,
                'timezone' => 'America/Sao_Paulo',
            ],
        );

        // Garante que usuários demo NÃO têm membership no sentinela.
        $demoEmails = ['admin@example.com', 'operador@example.com', 'viewer@example.com'];
        $userIds = User::query()->whereIn('email', $demoEmails)->pluck('id');
        OfficeMembership::query()
            ->where('office_id', $sentinel->id)
            ->whereIn('user_id', $userIds)
            ->delete();

        // Departamento e cliente com mesmo root do shared (isolamento).
        WorkDepartment::query()->updateOrCreate(
            ['office_id' => $sentinel->id, 'code' => 'FIS'],
            ['name' => 'Fiscal', 'color' => '#2563eb', 'is_active' => true],
        );

        $sharedRoot = $demoClients['shared']->root_cnpj;
        Client::query()->updateOrCreate(
            ['office_id' => $sentinel->id, 'root_cnpj' => $sharedRoot],
            [
                'legal_name' => 'SENTINEL Cliente Compartilhado Isolamento LTDA',
                'display_name' => '[SENTINEL] Cliente Isolamento',
                'is_active' => true,
                'notes' => $this->marker.' sentinel.client.shared',
            ],
        );

        return $sentinel;
    }

    /**
     * @param  array<string, Client>  $demoClients
     */
    private function seedSentinelVisibleData(Office $sentinel, array $demoClients): void
    {
        $dept = WorkDepartment::query()
            ->where('office_id', $sentinel->id)
            ->where('code', 'FIS')
            ->firstOrFail();

        $client = Client::query()
            ->where('office_id', $sentinel->id)
            ->where('root_cnpj', $demoClients['shared']->root_cnpj)
            ->firstOrFail();

        $title = 'SENTINEL · leak-probe.curr';
        $periodAttrs = $this->periodAttributes($this->anchor->format('Y-m'));
        $process = OperationalProcess::query()->updateOrCreate(
            ['office_id' => $sentinel->id, 'title' => $title],
            [
                'client_id' => $client->id,
                'origin' => ProcessOrigin::Manual,
                'description' => $this->marker.' sentinel.process.leak-probe',
                ...$periodAttrs,
                'due_date' => $this->anchor->toDateString(),
                'subject_to_fine' => true,
                'work_department_id' => $dept->id,
                'status' => ProcessStatus::EmProgresso,
                'lock_version' => 1,
            ],
        );
        $this->counts['sentinel_processes']++;

        OperationalTask::query()->updateOrCreate(
            [
                'office_id' => $sentinel->id,
                'operational_process_id' => $process->id,
                'sort_order' => 1,
            ],
            [
                'title' => 'Tarefa sentinela visível se vazar office_id',
                'description' => $this->marker.' sentinel.task.1',
                'status' => TaskStatus::AFazer,
                'due_date' => $this->anchor->toDateString(),
                'work_department_id' => $dept->id,
                'is_required' => true,
                'is_critical' => true,
                'requires_evidence' => false,
                'lock_version' => 1,
            ],
        );
        $this->counts['sentinel_tasks']++;
    }

    private function emitSummary(Office $office): void
    {
        $summary = sprintf(
            'OperationalWorkDemoSeeder ok office=%s anchor=%s depts=%d clients=%d templates=%d processes=%d tasks=%d comments=%d evidences=%d sentinel_p=%d sentinel_t=%d',
            $office->slug,
            $this->anchor->toDateString(),
            $this->counts['departments'],
            $this->counts['clients'],
            $this->counts['templates'],
            $this->counts['processes'],
            $this->counts['tasks'],
            $this->counts['comments'],
            $this->counts['evidences'],
            $this->counts['sentinel_processes'],
            $this->counts['sentinel_tasks'],
        );

        $this->command?->info($summary);
    }

    /**
     * @return array{competence: string, reference_period_type: string, reference_period_start: string, reference_period_end: string}
     */
    private function periodAttributes(string $competence): array
    {
        $period = ReferencePeriod::fromString($competence);

        return [
            'competence' => $period->value(),
            'reference_period_type' => $period->type->value,
            'reference_period_start' => $period->startDate(),
            'reference_period_end' => $period->endDate(),
        ];
    }
}

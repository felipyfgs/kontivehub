<?php

namespace App\Services\Work;

use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;
use App\Models\Client;
use App\Models\ProcessTemplate;
use App\Support\CurrentOffice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agregação office-scoped de processos por cliente ou rotina (SQL).
 * Não materializa a lista de processos na resposta de grupos.
 */
final class OperationalProcessGroupQuery
{
    public const MANUAL_KEY = 'manual';

    public const MANUAL_LABEL = 'Sem rotina';

    /** @var list<string> */
    public const SORT_WHITELIST = [
        'label',
        'process_count',
        'open_task_count',
        'next_due_date',
        'progress_percent',
    ];

    public function __construct(
        private readonly CurrentOffice $currentOffice,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $groupBy = (string) ($filters['group_by'] ?? '');
        if (! in_array($groupBy, ['client', 'routine'], true)) {
            throw ValidationException::withMessages([
                'group_by' => ['Informe group_by=client ou group_by=routine.'],
            ]);
        }

        $officeId = (int) $this->currentOffice->id();
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);
        $page = max((int) ($filters['page'] ?? 1), 1);

        $sort = (string) ($filters['sort'] ?? 'label');
        if (! in_array($sort, self::SORT_WHITELIST, true)) {
            $sort = 'label';
        }
        $direction = strtolower((string) ($filters['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        $query = $this->buildGroupedQuery($officeId, $groupBy, $filters);

        $total = (int) DB::query()->fromSub($query->clone(), 'process_groups')->count();

        $query->orderBy($sort, $direction)
            ->orderBy('group_key', $direction);

        /** @var Collection<int, object> $rows */
        $rows = $query->forPage($page, $perPage)->get();

        $items = $this->hydrateRows($rows, $groupBy, $officeId);

        return new Paginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
    }

    /**
     * Resolve sort seguro (whitelist) — útil em testes unitários.
     *
     * @return array{0: string, 1: 'asc'|'desc'}
     */
    public function resolveSort(?string $sort, ?string $direction): array
    {
        $resolvedSort = in_array((string) $sort, self::SORT_WHITELIST, true)
            ? (string) $sort
            : 'label';
        $resolvedDirection = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';

        return [$resolvedSort, $resolvedDirection];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function buildGroupedQuery(int $officeId, string $groupBy, array $filters): Builder
    {
        $terminal = [
            TaskStatus::Concluida->value,
            TaskStatus::Dispensada->value,
        ];
        $terminalList = "'".implode("','", $terminal)."'";

        $taskAgg = DB::table('operational_tasks')
            ->select([
                'operational_process_id',
                DB::raw('COUNT(*) as task_count'),
                DB::raw("SUM(CASE WHEN status IN ({$terminalList}) THEN 1 ELSE 0 END) as completed_task_count"),
                DB::raw("SUM(CASE WHEN status NOT IN ({$terminalList}) THEN 1 ELSE 0 END) as open_task_count"),
            ])
            ->where('office_id', $officeId)
            ->groupBy('operational_process_id');

        $statusSelects = [];
        foreach (ProcessStatus::cases() as $status) {
            $value = $status->value;
            $statusSelects[] = DB::raw(
                "SUM(CASE WHEN p.status = '{$value}' THEN 1 ELSE 0 END) as status_{$value}"
            );
        }

        $query = DB::table('operational_processes as p')
            ->leftJoinSub($taskAgg, 't', 't.operational_process_id', '=', 'p.id')
            ->where('p.office_id', $officeId);

        $this->applyFilters($query, $filters);

        $aggregates = array_merge([
            DB::raw('COUNT(*) as process_count'),
            DB::raw('COUNT(DISTINCT p.client_id) as client_count'),
            DB::raw('COALESCE(SUM(t.task_count), 0) as task_count'),
            DB::raw('COALESCE(SUM(t.open_task_count), 0) as open_task_count'),
            DB::raw('COALESCE(SUM(t.completed_task_count), 0) as completed_task_count'),
            // SQL portável (SQLite + Postgres): mesma regra do hydrate (0 se sem tasks).
            DB::raw(
                'CASE WHEN COALESCE(SUM(t.task_count), 0) = 0 THEN 0 '
                .'ELSE CAST(ROUND((COALESCE(SUM(t.completed_task_count), 0) * 100.0) '
                .'/ COALESCE(SUM(t.task_count), 0)) AS INTEGER) END as progress_percent'
            ),
            DB::raw('MIN(p.due_date) as next_due_date'),
        ], $statusSelects);

        if ($groupBy === 'client') {
            $query->join('clients as c', 'c.id', '=', 'p.client_id')
                ->select(array_merge([
                    DB::raw('CAST(p.client_id AS TEXT) as group_key'),
                    DB::raw("COALESCE(NULLIF(TRIM(c.display_name), ''), c.legal_name) as label"),
                    'p.client_id as entity_id',
                ], $aggregates))
                ->groupBy('p.client_id', 'c.display_name', 'c.legal_name');
        } else {
            $query->leftJoin('process_templates as pt', 'pt.id', '=', 'p.process_template_id')
                ->select(array_merge([
                    DB::raw(
                        "CASE WHEN p.process_template_id IS NULL THEN '".self::MANUAL_KEY."' "
                        .'ELSE CAST(p.process_template_id AS TEXT) END as group_key'
                    ),
                    DB::raw(
                        "CASE WHEN p.process_template_id IS NULL THEN '".self::MANUAL_LABEL."' "
                        .'ELSE pt.name END as label'
                    ),
                    'p.process_template_id as entity_id',
                ], $aggregates))
                ->groupBy('p.process_template_id', 'pt.name');
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! ($filters['include_archived'] ?? false)) {
            $query->whereNull('p.archived_at');
        }

        if (! empty($filters['competence'])) {
            $query->where('p.competence', (string) $filters['competence']);
        }

        if (! empty($filters['client_id'])) {
            $query->where('p.client_id', (int) $filters['client_id']);
        }

        if (! empty($filters['status'])) {
            $query->where('p.status', (string) $filters['status']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('p.work_department_id', (int) $filters['department_id']);
        }

        if (! empty($filters['assignee_membership_id'])) {
            $query->where('p.assignee_membership_id', (int) $filters['assignee_membership_id']);
        }

        if (! empty($filters['q'])) {
            $needle = '%'.mb_strtolower((string) $filters['q']).'%';
            $query->where(function (Builder $search) use ($needle): void {
                $search->whereRaw('LOWER(p.title) LIKE ?', [$needle])
                    ->orWhereExists(function (Builder $sub) use ($needle): void {
                        $sub->select(DB::raw(1))
                            ->from('clients')
                            ->whereColumn('clients.id', 'p.client_id')
                            ->where(function (Builder $client) use ($needle): void {
                                $client->whereRaw('LOWER(clients.legal_name) LIKE ?', [$needle])
                                    ->orWhereRaw("LOWER(COALESCE(clients.display_name, '')) LIKE ?", [$needle]);
                            });
                    });
            });
        }
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return list<array<string, mixed>>
     */
    private function hydrateRows(Collection $rows, string $groupBy, int $officeId): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        if ($groupBy === 'client') {
            $ids = $rows->pluck('entity_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
            $clients = Client::query()
                ->where('office_id', $officeId)
                ->whereIn('id', $ids)
                ->with(['establishments:id,client_id,cnpj,is_matrix'])
                ->get()
                ->keyBy('id');

            return $rows->map(function (object $row) use ($clients): array {
                $clientId = (int) $row->entity_id;
                $client = $clients->get($clientId);
                $taskCount = (int) $row->task_count;
                $completed = (int) $row->completed_task_count;

                return [
                    'key' => (string) $row->group_key,
                    'label' => (string) $row->label,
                    'client' => $client ? [
                        'id' => $client->id,
                        'name' => $client->displayLabel(),
                        'cnpj_masked' => $this->clientCnpjMasked($client),
                    ] : [
                        'id' => $clientId,
                        'name' => (string) $row->label,
                        'cnpj_masked' => null,
                    ],
                    'client_count' => (int) $row->client_count,
                    'process_count' => (int) $row->process_count,
                    'task_count' => $taskCount,
                    'open_task_count' => (int) $row->open_task_count,
                    'completed_task_count' => $completed,
                    'progress_percent' => $taskCount > 0 ? (int) round(($completed / $taskCount) * 100) : 0,
                    'status_counts' => $this->statusCountsFromRow($row),
                    'next_due_date' => $this->normalizeDate($row->next_due_date),
                ];
            })->values()->all();
        }

        $templateIds = $rows->pluck('entity_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $templates = ProcessTemplate::query()
            ->where('office_id', $officeId)
            ->whereIn('id', $templateIds)
            ->get(['id', 'name'])
            ->keyBy('id');

        return $rows->map(function (object $row) use ($templates): array {
            $taskCount = (int) $row->task_count;
            $completed = (int) $row->completed_task_count;
            $isManual = (string) $row->group_key === self::MANUAL_KEY;
            $templateId = $row->entity_id !== null ? (int) $row->entity_id : null;
            $template = $templateId !== null ? $templates->get($templateId) : null;

            return [
                'key' => (string) $row->group_key,
                'label' => $isManual ? self::MANUAL_LABEL : (string) $row->label,
                'routine' => $isManual ? null : [
                    'id' => $template?->id ?? $templateId,
                    'name' => $template?->name ?? (string) $row->label,
                ],
                'client_count' => (int) $row->client_count,
                'process_count' => (int) $row->process_count,
                'task_count' => $taskCount,
                'open_task_count' => (int) $row->open_task_count,
                'completed_task_count' => $completed,
                'progress_percent' => $taskCount > 0 ? (int) round(($completed / $taskCount) * 100) : 0,
                'status_counts' => $this->statusCountsFromRow($row),
                'next_due_date' => $this->normalizeDate($row->next_due_date),
            ];
        })->values()->all();
    }

    /**
     * @return array<string, int>
     */
    private function statusCountsFromRow(object $row): array
    {
        $counts = [];
        foreach (ProcessStatus::cases() as $status) {
            $prop = 'status_'.$status->value;
            $counts[$status->value] = (int) ($row->{$prop} ?? 0);
        }

        return $counts;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = (string) $value;

        return strlen($raw) >= 10 ? substr($raw, 0, 10) : $raw;
    }

    private function clientCnpjMasked(Client $client): ?string
    {
        $cnpj = null;
        if ($client->relationLoaded('establishments')) {
            $cnpj = $client->establishments
                ->sortByDesc('is_matrix')
                ->first()?->cnpj;
        }
        $digits = preg_replace('/\D+/', '', (string) $cnpj) ?? '';
        if (strlen($digits) !== 14) {
            return null;
        }

        return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3)
            .'/'.substr($digits, 8, 4).'-'.substr($digits, 12, 2);
    }
}

<?php

namespace App\Services\Work;

use App\Domain\Work\DueDateCalculator;
use App\Domain\Work\WorkRiskCalculator;
use App\Enums\Work\OperationalExportStatus;
use App\Enums\Work\WorkRisk;
use App\Models\OperationalExport;
use App\Models\OperationalTask;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOffice;
use App\Support\Work\OfficeTimezone;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Export CSV operacional assíncrono (sem ZIP/XML fiscal).
 */
final class OperationalExportService
{
    /** Colunas allowlisted do CSV. */
    public const COLUMNS = [
        'task_id',
        'task_title',
        'task_status',
        'task_due_date',
        'effective_due_date',
        'risks',
        'is_critical',
        'process_id',
        'process_title',
        'competence',
        'client_id',
        'client_name',
        'department_code',
        'assignee_name',
        'subject_to_fine',
    ];

    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly AuditLogger $audit,
        private readonly WorkRiskCalculator $risks = new WorkRiskCalculator,
        private readonly DueDateCalculator $dates = new DueDateCalculator,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function create(array $filters): OperationalExport
    {
        unset($filters['office_id']);

        $export = OperationalExport::query()->create([
            'office_id' => $this->currentOffice->id(),
            'requested_by_membership_id' => $this->currentOffice->membership()?->id,
            'status' => OperationalExportStatus::Pending,
            'filters_snapshot' => $filters,
            'expires_at' => now()->addDays(2),
        ]);

        $this->audit->record('work.export.create', 'SUCCESS', $export, [
            'filters' => $filters,
        ]);

        $this->build($export);

        return $export->fresh();
    }

    public function build(OperationalExport $export): void
    {
        $export->forceFill(['status' => OperationalExportStatus::Processing])->save();

        try {
            $office = $export->office;
            $tz = OfficeTimezone::for($office);
            $today = $this->dates->todayInOffice($tz);

            $query = OperationalTask::query()
                ->with(['process.client', 'department', 'assigneeMembership.user'])
                ->where('office_id', $export->office_id);

            $filters = $export->filters_snapshot ?? [];
            if (! empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            if (! empty($filters['department_id'])) {
                $query->where('work_department_id', (int) $filters['department_id']);
            }
            if (! empty($filters['client_id'])) {
                $query->whereHas('process', fn ($q) => $q->where('client_id', (int) $filters['client_id']));
            }

            $rows = [];
            $rows[] = self::COLUMNS;

            foreach ($query->orderBy('id')->cursor() as $task) {
                $effective = $this->risks->effectiveDueDate(
                    $task->due_date?->format('Y-m-d'),
                    $task->process?->target_due_date?->format('Y-m-d'),
                    $task->process?->due_date?->format('Y-m-d'),
                );
                $riskList = $this->risks->forTask(
                    $task->status,
                    $task->due_date?->format('Y-m-d'),
                    $task->process?->target_due_date?->format('Y-m-d'),
                    $task->process?->due_date?->format('Y-m-d'),
                    (bool) ($task->process?->subject_to_fine),
                    $task->assignee_membership_id,
                    $today,
                );

                $rows[] = [
                    $task->id,
                    $task->title,
                    $task->status->value,
                    $task->due_date?->format('Y-m-d') ?? '',
                    $effective ?? '',
                    implode('|', array_map(fn (WorkRisk $r) => $r->value, $riskList)),
                    $task->is_critical ? '1' : '0',
                    $task->operational_process_id,
                    $task->process?->title ?? '',
                    $task->process?->competence ?? '',
                    $task->process?->client_id ?? '',
                    $task->process?->client?->display_name
                        ?: $task->process?->client?->legal_name
                        ?: '',
                    $task->department?->code ?? '',
                    $task->assigneeMembership?->user?->name ?? '',
                    $task->process?->subject_to_fine ? '1' : '0',
                ];
            }

            $csv = '';
            foreach ($rows as $row) {
                $csv .= $this->csvLine($row);
            }

            $path = 'operational-exports/'.$export->office_id.'/'.$export->id.'_'.Str::uuid().'.csv';
            Storage::disk('local')->put($path, $csv);

            $export->forceFill([
                'status' => OperationalExportStatus::Ready,
                'storage_path' => $path,
                'byte_size' => strlen($csv),
                'row_count' => max(count($rows) - 1, 0),
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            $export->forceFill([
                'status' => OperationalExportStatus::Failed,
                'error_message' => 'Falha ao gerar exportação.',
            ])->save();
            report($e);
        }
    }

    /**
     * @param  list<scalar|null>  $fields
     */
    private function csvLine(array $fields): string
    {
        $escaped = array_map(function ($v) {
            $s = (string) ($v ?? '');
            $s = str_replace('"', '""', $s);

            return '"'.$s.'"';
        }, $fields);

        return implode(',', $escaped)."\n";
    }
}

<?php

namespace App\Services\Work;

use App\Domain\Work\DueDateCalculator;
use App\Domain\Work\WorkRiskCalculator;
use App\DTO\Work\ExportFiltersData;
use App\Enums\Work\WorkExportStatus;
use App\Enums\Work\WorkRisk;
use App\Models\WorkExport;
use App\Models\WorkTask;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;
use App\Support\Work\TenantTimezone;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Export CSV operacional assíncrono (sem ZIP/XML fiscal).
 */
final class ExportService
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
        private readonly CurrentTenant $currentTenant,
        private readonly AuditLogger $audit,
        private readonly WorkRiskCalculator $risks = new WorkRiskCalculator,
        private readonly DueDateCalculator $dates = new DueDateCalculator,
    ) {}

    public function create(ExportFiltersData $data): WorkExport
    {
        $filters = $data->toArray();

        $export = WorkExport::query()->create([
            'tenant_id' => $this->currentTenant->id(),
            'requested_by_membership_id' => $this->currentTenant->realMembership()?->id,
            'status' => WorkExportStatus::Pending,
            'filters_snapshot' => $filters,
            'expires_at' => now()->addDays(2),
        ]);

        $this->audit->record('work.export.create', 'SUCCESS', $export, [
            'filters' => $filters,
        ]);

        $this->build($export);

        return $export->fresh();
    }

    public function build(WorkExport $export): void
    {
        $export->forceFill(['status' => WorkExportStatus::Processing])->save();

        $stream = null;
        $storedPath = null;

        try {
            $tenant = $export->tenant;
            $tz = TenantTimezone::for($tenant);
            $today = $this->dates->todayInTenant($tz);

            $query = WorkTask::query()
                ->with(['process.client', 'department', 'assigneeMembership.user'])
                ->where('tenant_id', $export->tenant_id);

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

            $stream = tmpfile();
            if ($stream === false) {
                throw new RuntimeException('Unable to allocate the work export stream.');
            }
            if (fwrite($stream, $this->csvLine(self::COLUMNS)) === false) {
                throw new RuntimeException('Unable to write the work export header.');
            }

            $rowCount = 0;

            foreach ($query->lazyById(500) as $task) {
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

                $row = [
                    $task->id,
                    $task->title,
                    $task->status->value,
                    $task->due_date?->format('Y-m-d') ?? '',
                    $effective ?? '',
                    implode('|', array_map(fn (WorkRisk $r) => $r->value, $riskList)),
                    $task->is_critical ? '1' : '0',
                    $task->work_process_id,
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
                if (fwrite($stream, $this->csvLine($row)) === false) {
                    throw new RuntimeException('Unable to write a work export row.');
                }
                $rowCount++;
            }

            $byteSize = ftell($stream);
            if ($byteSize === false || ! rewind($stream)) {
                throw new RuntimeException('Unable to finalize the work export stream.');
            }
            $path = 'work-exports/'.$export->tenant_id.'/'.$export->id.'_'.Str::uuid().'.csv';
            if (! Storage::disk('local')->put($path, $stream)) {
                throw new RuntimeException('Unable to persist the work export.');
            }
            $storedPath = $path;

            $export->forceFill([
                'status' => WorkExportStatus::Ready,
                'storage_path' => $path,
                'byte_size' => $byteSize,
                'row_count' => $rowCount,
                'completed_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            if ($storedPath !== null) {
                Storage::disk('local')->delete($storedPath);
            }
            $export->forceFill([
                'status' => WorkExportStatus::Failed,
                'error_message' => 'Falha ao gerar exportação.',
                'storage_path' => null,
            ])->save();
            report($e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
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

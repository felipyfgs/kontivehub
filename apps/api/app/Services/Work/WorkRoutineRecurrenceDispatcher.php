<?php

namespace App\Services\Work;

use App\Domain\Work\ReferencePeriod;
use App\Domain\Work\WorkRoutineRecurrenceSchedule;
use App\Enums\Work\GenerationBatchStatus;
use App\Enums\Work\RecurrencePeriodOffset;
use App\Models\Office;
use App\Models\ProcessGenerationBatch;
use App\Models\ProcessTemplate;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOffice;
use App\Support\Work\OfficeTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/**
 * Dispatcher de recorrência: catch-up cronológico no fuso do Escritório + Lote idempotente.
 */
final class WorkRoutineRecurrenceDispatcher
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly OperationalProcessGenerationService $generation,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{dispatched: int, skipped: int, failed: int, catch_up: int}
     */
    public function dispatchDue(?CarbonImmutable $nowUtc = null): array
    {
        $nowUtc ??= CarbonImmutable::now('UTC');
        $dispatched = 0;
        $skipped = 0;
        $failed = 0;
        $catch_up = 0;

        $templates = ProcessTemplate::query()
            ->where('recurrence_enabled', true)
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $nowUtc)
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->with('office')
            ->limit(100)
            ->get();

        foreach ($templates as $template) {
            try {
                $result = $this->dispatchTemplate($template, $nowUtc);
                $dispatched += $result['dispatched'];
                $skipped += $result['skipped'];
                $catch_up += max(0, $result['dispatched'] - 1);
            } catch (Throwable $e) {
                $failed++;
                Log::warning('work.recurrence.dispatch_failed', [
                    'office_id' => $template->office_id,
                    'template_id' => $template->id,
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
        }

        return compact('dispatched', 'skipped', 'failed', 'catch_up');
    }

    /**
     * @return array{dispatched: int, skipped: int}
     */
    public function dispatchTemplate(ProcessTemplate $template, ?CarbonImmutable $nowUtc = null): array
    {
        $nowUtc ??= CarbonImmutable::now('UTC');
        $office = $template->office ?? Office::query()->findOrFail($template->office_id);

        $this->currentOffice->clear();
        $this->currentOffice->bindSystem($office);

        $schedule = $this->scheduleFromTemplate($template);
        if (! $schedule->enabled || $schedule->frequency === null || ! $template->is_active) {
            return ['dispatched' => 0, 'skipped' => 1];
        }

        $tz = OfficeTimezone::for($office);
        $dispatched = 0;
        $skipped = 0;
        $iterations = 0;

        while (
            $template->next_run_at !== null
            && CarbonImmutable::parse($template->next_run_at)->lessThanOrEqualTo($nowUtc)
            && $iterations < WorkRoutineRecurrenceSchedule::MAX_CATCH_UP
        ) {
            $iterations++;
            $runAtUtc = CarbonImmutable::parse($template->next_run_at)->utc();
            $runLocal = $runAtUtc->timezone($tz);
            $period = $schedule->periodForRunLocalDate($runLocal);

            try {
                $batch = $this->runGeneration($template, $period);
                if ($batch !== null) {
                    $dispatched++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                Log::warning('work.recurrence.period_failed', [
                    'office_id' => $office->id,
                    'template_id' => $template->id,
                    'period' => $period->value(),
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
                // Avança o cursor mesmo em falha de período para não travar a fila;
                // lote parcial/FAILED permanece auditável para retry HTTP.
                $dispatched++;
            }

            $next = $schedule->nextRunAtUtc($office, $runAtUtc);
            $template->forceFill(['next_run_at' => $next])->save();
            $template->refresh();
        }

        $this->audit->record('work.recurrence.dispatch', 'SUCCESS', $template, [
            'dispatched' => $dispatched,
            'skipped' => $skipped,
            'next_run_at' => $template->next_run_at?->toIso8601String(),
        ]);

        return compact('dispatched', 'skipped');
    }

    public static function idempotencyKey(int $officeId, int $templateId, ReferencePeriod $period): string
    {
        return sprintf('recurrence:%d:%d:%s', $officeId, $templateId, $period->value());
    }

    private function runGeneration(ProcessTemplate $template, ReferencePeriod $period): ?ProcessGenerationBatch
    {
        $key = self::idempotencyKey((int) $template->office_id, (int) $template->id, $period);

        $existing = ProcessGenerationBatch::query()
            ->where('office_id', $template->office_id)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing !== null) {
            if (in_array($existing->status, [
                GenerationBatchStatus::Completed,
                GenerationBatchStatus::Queued,
                GenerationBatchStatus::Processing,
            ], true)) {
                return $existing;
            }

            if ($existing->status === GenerationBatchStatus::Previewed) {
                return $this->confirmIfReady($existing);
            }

            if (in_array($existing->status, [
                GenerationBatchStatus::Failed,
                GenerationBatchStatus::CompletedWithErrors,
            ], true)) {
                return $this->generation->retryFailedItems($existing);
            }
        }

        $selection = [
            'rules' => $template->audience_rules ?? [],
        ];

        $batch = $this->generation->preview(
            $template,
            $period->value(),
            [],
            [],
            $key,
            $selection,
        );

        return $this->confirmIfReady($batch);
    }

    private function confirmIfReady(ProcessGenerationBatch $batch): ?ProcessGenerationBatch
    {
        $batch->loadMissing('items');
        $ready = $batch->items->where('is_blocked', false)->count();
        if ($ready === 0) {
            // Idempotente: audiência vazia ou só duplicatas — sem materialização.
            if ($batch->status === GenerationBatchStatus::Previewed) {
                $batch->forceFill([
                    'status' => GenerationBatchStatus::Completed,
                    'completed_at' => now(),
                    'expires_at' => null,
                ])->save();
            }

            return $batch->fresh(['items']);
        }

        try {
            return $this->generation->confirm($batch);
        } catch (ValidationException $e) {
            // Preview expirado / modelo alterado: deixa auditável e segue cursor.
            Log::info('work.recurrence.confirm_skipped', [
                'batch_id' => $batch->id,
                'errors' => $e->errors(),
            ]);

            return $batch->fresh(['items']);
        }
    }

    private function scheduleFromTemplate(ProcessTemplate $template): WorkRoutineRecurrenceSchedule
    {
        try {
            return WorkRoutineRecurrenceSchedule::fromArray([
                'recurrence_enabled' => (bool) $template->recurrence_enabled,
                'recurrence_frequency' => $template->recurrence_frequency?->value,
                'generation_day' => (int) ($template->generation_day ?? 1),
                'anchor_month' => $template->anchor_month,
                'period_offset' => ($template->period_offset ?? RecurrencePeriodOffset::Previous)->value,
            ]);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'recurrence' => [$e->getMessage()],
            ]);
        }
    }
}

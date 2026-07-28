<?php

namespace App\Actions\Outbound;

use App\Domain\Outbound\OperationalSla;
use App\DTO\Outbound\OutboundMonthlyExportData;
use App\DTO\Outbound\OutboundMonthlyExportResult;
use App\DTO\Outbound\OutboundPartialConfirmationData;
use App\DTO\Outbound\OutboundTargetAdvanceData;
use App\DTO\Outbound\OutboundTargetAdvanceResult;
use App\Enums\OutboundUrgencyBand;
use App\Exceptions\OutboundApiException;
use App\Models\OutboundMonthlyReadiness;
use App\Models\OutboundRetrievalRequest;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Outbound\OutboundMonthlyExportService;
use App\Services\Outbound\OutboundMonthlyReadinessService;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class ManageOutboundDeadlineAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private OutboundMonthlyReadinessService $readiness,
        private OutboundMonthlyExportService $monthlyExport,
        private AuditLogger $audit,
    ) {}

    public function confirmPartial(
        User $actor,
        OutboundPartialConfirmationData $data,
    ): OutboundMonthlyReadiness {
        return $this->readiness->confirmPartial(
            (int) $this->currentTenant->id(),
            $data->competence->value(),
            (int) $actor->id,
            $data->notes,
        );
    }

    public function exportMonthly(
        User $actor,
        OutboundMonthlyExportData $data,
    ): OutboundMonthlyExportResult {
        try {
            $result = $this->monthlyExport->createMonthlyExport(
                (int) $this->currentTenant->id(),
                (int) $actor->id,
                $data->competence->value(),
                $data->includeEvents,
                $data->notes,
            );
        } catch (InvalidArgumentException) {
            throw OutboundApiException::monthlyExportUnavailable();
        }

        return new OutboundMonthlyExportResult(
            export: $result['export'],
            readiness: $result['readiness'],
            hasManifest: $result['manifest_path'] !== null,
        );
    }

    public function advanceTarget(
        OutboundTargetAdvanceData $data,
    ): OutboundTargetAdvanceResult {
        $sla = OperationalSla::fromConfig(
            $this->currentTenant->tenant()?->deadline_timezone,
        );
        $deadlines = $sla->deadlinesFor($data->competence);
        if ($data->targetAt->greaterThanOrEqualTo($deadlines['due_at'])) {
            throw OutboundApiException::invalidOperation(
                'outbound_target_after_due',
                'Não é permitido postergar a meta além do due_at (fim do dia 1).',
            );
        }
        if ($data->targetAt->greaterThan($deadlines['target_at'])) {
            throw OutboundApiException::invalidOperation(
                'outbound_target_postponement',
                'Só é permitido antecipar a meta interna, não postergá-la.',
            );
        }
        if ($data->targetAt->diffInHours($deadlines['due_at']) < 24) {
            throw OutboundApiException::invalidOperation(
                'outbound_target_buffer_too_short',
                'Buffer interno não pode ser inferior a 24 horas.',
            );
        }

        $tenantId = (int) $this->currentTenant->id();

        return DB::transaction(function () use (
            $data,
            $deadlines,
            $tenantId,
        ): OutboundTargetAdvanceResult {
            $updated = OutboundRetrievalRequest::query()
                ->where('tenant_id', $tenantId)
                ->where('competence', $data->competence->value())
                ->whereNotIn('urgency_band', [
                    OutboundUrgencyBand::Captured->value,
                ])
                ->update(['target_at' => $data->targetAt]);

            $this->audit->record(
                'outbound.deadline.advance_target',
                'SUCCESS',
                null,
                [
                    'competence' => $data->competence->value(),
                    'target_at' => $data->targetAt->toIso8601String(),
                    'rows' => $updated,
                ],
                null,
                $tenantId,
            );

            return new OutboundTargetAdvanceResult(
                competence: $data->competence->value(),
                targetAt: $data->targetAt,
                dueAt: $deadlines['due_at'],
                updatedRows: $updated,
            );
        });
    }
}

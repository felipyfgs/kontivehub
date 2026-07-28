<?php

namespace App\Http\Resources\Outbound;

use App\Enums\OutboundCaptureMode;
use App\Enums\OutboundFiscalModel;
use App\Enums\OutboundRetrievalOrigin;
use App\Enums\OutboundRetrievalStatus;
use App\Enums\OutboundUrgencyBand;
use App\Enums\SvrsNfceFailureReason;
use App\Enums\SvrsNfceRecoveryStatus;
use App\Models\OutboundRetrievalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundRetrievalRequestResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OutboundRetrievalRequest $retrieval */
        $retrieval = $this->resource;

        return [
            'id' => $retrieval->id,
            'profile_id' => $retrieval->outbound_capture_profile_id,
            'number_state_id' => $retrieval->outbound_number_state_id,
            'establishment_id' => $retrieval->establishment_id,
            'environment' => $retrieval->environment,
            'model' => $retrieval->model instanceof OutboundFiscalModel
                ? $retrieval->model->value
                : $retrieval->model,
            'direction' => $retrieval->direction,
            'competence' => $retrieval->competence,
            'status' => $retrieval->status instanceof OutboundRetrievalStatus
                ? $retrieval->status->value
                : $retrieval->status,
            'mode' => $retrieval->mode instanceof OutboundCaptureMode
                ? $retrieval->mode->value
                : $retrieval->mode,
            'origin' => $retrieval->origin instanceof OutboundRetrievalOrigin
                ? $retrieval->origin->value
                : $retrieval->origin,
            'access_key_masked' => $this->maskAccessKey(
                is_string($retrieval->access_key)
                    ? $retrieval->access_key
                    : null,
            ),
            'recovery_status' => $retrieval->recovery_status instanceof SvrsNfceRecoveryStatus
                ? $retrieval->recovery_status->value
                : $retrieval->recovery_status,
            'failure_reason' => $retrieval->failure_reason instanceof SvrsNfceFailureReason
                ? $retrieval->failure_reason->value
                : $retrieval->failure_reason,
            'failure_label' => $retrieval->failure_reason instanceof SvrsNfceFailureReason
                ? $retrieval->failure_reason->label()
                : null,
            'attempt_count' => $retrieval->attempt_count,
            'svrs_transaction_count' => $retrieval->svrs_transaction_count,
            'next_attempt_at' => $retrieval->next_attempt_at?->toIso8601String(),
            'due_at' => $retrieval->due_at?->toIso8601String(),
            'target_at' => $retrieval->target_at?->toIso8601String(),
            'urgency_band' => $retrieval->urgency_band?->value,
            'deadline_status' => $retrieval->deadline_status?->value,
            'capacity_at_risk' => (bool) $retrieval->capacity_at_risk,
            'captured_at' => $retrieval->captured_at?->toIso8601String(),
            'captured_before_due' => $retrieval->captured_before_due,
            'capture_source' => $retrieval->capture_source,
            'correlation_id' => $retrieval->correlation_id,
            'sha256' => $retrieval->sha256,
            'external_ref' => $retrieval->external_ref,
            'expires_at' => $retrieval->expires_at?->toIso8601String(),
            'files_ingested' => $retrieval->files_ingested,
            'files_expected' => $retrieval->files_expected,
            'root_cnpj' => $retrieval->root_cnpj,
            'next_step' => $this->nextStep($retrieval),
        ];
    }

    private function nextStep(OutboundRetrievalRequest $retrieval): string
    {
        if ($retrieval->capacity_at_risk) {
            return 'PREFER_AUTXML_XML_ZIP_OR_OFFICIAL_PACKAGE';
        }

        return match ($retrieval->urgency_band) {
            OutboundUrgencyBand::Contingency,
            OutboundUrgencyBand::Overdue => 'ASSISTED_IMPORT',
            OutboundUrgencyBand::Attention => 'PREPARE_ASSISTED_BATCH',
            default => 'WAIT_OR_PREFER_AUTXML',
        };
    }

    private function maskAccessKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }
        $key = strtoupper($key);
        if (strlen($key) < 12) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 6)
            .str_repeat('*', max(0, strlen($key) - 10))
            .substr($key, -4);
    }
}

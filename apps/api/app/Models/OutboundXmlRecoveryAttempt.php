<?php

namespace App\Models;

use App\Enums\SvrsNfceFailureReason;
use App\Enums\SvrsNfceRecoveryStatus;
use App\Enums\SvrsNfceTransportOutcome;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'ma_outbound_retrieval_request_id',
    'outbound_capture_profile_id',
    'outbound_number_state_id',
    'access_key',
    'correlation_id',
    'attempt_number',
    'result',
    'failure_reason',
    'transport_outcome',
    'http_status',
    'parser_version',
    'get_latency_ms',
    'post_latency_ms',
    'total_latency_ms',
    'sanitized_detail',
    'sha256',
    'started_at',
    'finished_at',
])]
class OutboundXmlRecoveryAttempt extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'result' => SvrsNfceRecoveryStatus::class,
            'failure_reason' => SvrsNfceFailureReason::class,
            'transport_outcome' => SvrsNfceTransportOutcome::class,
            'attempt_number' => 'integer',
            'http_status' => 'integer',
            'get_latency_ms' => 'integer',
            'post_latency_ms' => 'integer',
            'total_latency_ms' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function retrievalRequest(): BelongsTo
    {
        return $this->belongsTo(MaOutboundRetrievalRequest::class, 'ma_outbound_retrieval_request_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(OutboundCaptureProfile::class, 'outbound_capture_profile_id');
    }

    public function numberState(): BelongsTo
    {
        return $this->belongsTo(OutboundNumberState::class, 'outbound_number_state_id');
    }

    /**
     * DTO público sanitizado — sem HTML, XML, PFX, cookie ou vault_object_id.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'retrieval_request_id' => $this->ma_outbound_retrieval_request_id,
            'profile_id' => $this->outbound_capture_profile_id,
            'access_key_masked' => $this->maskAccessKey($this->access_key),
            'correlation_id' => $this->correlation_id,
            'attempt_number' => $this->attempt_number,
            'result' => $this->result?->value ?? $this->getAttribute('result'),
            'failure_reason' => $this->failure_reason?->value,
            'failure_label' => $this->failure_reason?->label(),
            'transport_outcome' => $this->transport_outcome?->value,
            'http_status' => $this->http_status,
            'parser_version' => $this->parser_version,
            'get_latency_ms' => $this->get_latency_ms,
            'post_latency_ms' => $this->post_latency_ms,
            'total_latency_ms' => $this->total_latency_ms,
            'sanitized_detail' => $this->sanitized_detail,
            'sha256' => $this->sha256,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
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

        return substr($key, 0, 6).str_repeat('*', max(0, strlen($key) - 10)).substr($key, -4);
    }
}

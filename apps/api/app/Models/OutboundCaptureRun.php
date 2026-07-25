<?php

namespace App\Models;

use App\Enums\OutboundCaptureRunStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id', 'outbound_capture_profile_id', 'outbound_series_cursor_id',
    'run_type', 'status', 'nnf_start', 'nnf_end', 'numbers_consulted', 'keys_discovered',
    'xml_persisted', 'gaps_open', 'attempts_total', 'result_summary', 'last_error',
    'started_at', 'finished_at', 'triggered_by', 'user_id', 'metrics',
])]
class OutboundCaptureRun extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'status' => OutboundCaptureRunStatus::class,
            'nnf_start' => 'integer',
            'nnf_end' => 'integer',
            'numbers_consulted' => 'integer',
            'keys_discovered' => 'integer',
            'xml_persisted' => 'integer',
            'gaps_open' => 'integer',
            'attempts_total' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'metrics' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(OutboundCaptureProfile::class, 'outbound_capture_profile_id');
    }

    public function seriesCursor(): BelongsTo
    {
        return $this->belongsTo(OutboundSeriesCursor::class, 'outbound_series_cursor_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'profile_id' => $this->outbound_capture_profile_id,
            'series_cursor_id' => $this->outbound_series_cursor_id,
            'run_type' => $this->run_type,
            'status' => $this->status->value,
            'position_kind' => 'nNF',
            'nnf_start' => $this->nnf_start,
            'nnf_end' => $this->nnf_end,
            'numbers_consulted' => $this->numbers_consulted,
            'keys_discovered' => $this->keys_discovered,
            'xml_persisted' => $this->xml_persisted,
            'gaps_open' => $this->gaps_open,
            'attempts_total' => $this->attempts_total,
            'result_summary' => $this->result_summary,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'triggered_by' => $this->triggered_by,
        ];
    }
}

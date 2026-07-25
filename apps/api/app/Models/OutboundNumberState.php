<?php

namespace App\Models;

use App\Enums\OutboundNumberStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id', 'outbound_capture_profile_id', 'outbound_series_cursor_id',
    'series', 'nnf', 'status', 'candidate_access_key', 'candidate_cnf',
    'discovered_access_key', 'last_cstat', 'last_xmotivo', 'protocol',
    'attempts', 'last_attempt_at', 'next_attempt_at', 'key_discovered_at',
    'xml_captured_at', 'dfe_document_id', 'sanitized_response', 'block_reason',
])]
class OutboundNumberState extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'status' => OutboundNumberStatus::class,
            'series' => 'integer',
            'nnf' => 'integer',
            'attempts' => 'integer',
            'last_attempt_at' => 'immutable_datetime',
            'next_attempt_at' => 'immutable_datetime',
            'key_discovered_at' => 'immutable_datetime',
            'xml_captured_at' => 'immutable_datetime',
            'sanitized_response' => 'array',
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

    public function dfeDocument(): BelongsTo
    {
        return $this->belongsTo(DfeDocument::class, 'dfe_document_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'series' => $this->series,
            'nnf' => $this->nnf,
            'status' => $this->status->value,
            'candidate_access_key' => $this->candidate_access_key,
            'discovered_access_key' => $this->discovered_access_key,
            'last_cstat' => $this->last_cstat,
            'attempts' => $this->attempts,
            'next_attempt_at' => $this->next_attempt_at?->toIso8601String(),
            'key_discovered_at' => $this->key_discovered_at?->toIso8601String(),
            'xml_captured_at' => $this->xml_captured_at?->toIso8601String(),
            'has_full_xml' => $this->dfe_document_id !== null && $this->xml_captured_at !== null,
        ];
    }
}

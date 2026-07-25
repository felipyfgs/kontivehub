<?php

namespace App\Models;

use App\Enums\OutboundFiscalModel;
use App\Enums\OutboundSeriesStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cursor de série por nNF — NÃO possui last_nsu.
 */
#[Fillable([
    'office_id', 'outbound_capture_profile_id', 'establishment_id', 'environment', 'model',
    'series', 'seed_nnf', 'discovery_position', 'highest_confirmed_nnf', 'status', 'tp_emis',
    'seed_access_key', 'seed_vault_object_id', 'seed_sha256', 'seed_issued_at',
    'next_run_at', 'last_run_at', 'locked_at', 'lock_owner', 'last_error', 'last_cstat',
    'series_closed_for_mutation', 'series_closed_at', 'erp_coordination_ref',
])]
#[Hidden(['seed_vault_object_id'])]
class OutboundSeriesCursor extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'model' => OutboundFiscalModel::class,
            'status' => OutboundSeriesStatus::class,
            'series' => 'integer',
            'seed_nnf' => 'integer',
            'discovery_position' => 'integer',
            'highest_confirmed_nnf' => 'integer',
            'seed_issued_at' => 'immutable_datetime',
            'next_run_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'series_closed_for_mutation' => 'boolean',
            'series_closed_at' => 'immutable_datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(OutboundCaptureProfile::class, 'outbound_capture_profile_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function numberStates(): HasMany
    {
        return $this->hasMany(OutboundNumberState::class);
    }

    public function captureRuns(): HasMany
    {
        return $this->hasMany(OutboundCaptureRun::class);
    }

    /**
     * Invariante: posição é nNF, nunca NSU.
     */
    public function positionKind(): string
    {
        return 'nNF';
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'profile_id' => $this->outbound_capture_profile_id,
            'establishment_id' => $this->establishment_id,
            'environment' => $this->environment,
            'model' => $this->model->value,
            'series' => $this->series,
            'seed_nnf' => $this->seed_nnf,
            'discovery_position' => $this->discovery_position,
            'position_kind' => $this->positionKind(),
            'highest_confirmed_nnf' => $this->highest_confirmed_nnf,
            'status' => $this->status->value,
            'tp_emis' => $this->tp_emis,
            'seed_access_key' => $this->seed_access_key,
            'seed_issued_at' => $this->seed_issued_at?->toIso8601String(),
            'next_run_at' => $this->next_run_at?->toIso8601String(),
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'series_closed_for_mutation' => $this->series_closed_for_mutation,
        ];
    }
}

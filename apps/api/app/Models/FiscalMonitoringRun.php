<?php

namespace App\Models;

use App\Casts\FiscalSourceProvenanceCast;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Enums\FiscalVerificationState;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'office_id',
    'client_id',
    'fiscal_category_id',
    'competence_id',
    'schedule_id',
    'last_update_event_id',
    'system_code',
    'service_code',
    'operation_code',
    'operation_key',
    'source_provenance',
    'verification_state',
    'trigger',
    'idempotency_key',
    'status',
    'result',
    'situation',
    'coverage',
    'mutability',
    'attempt',
    'parent_run_id',
    'correlation_id',
    'progress_cursor',
    'progress',
    'items_processed',
    'pages_processed',
    'skip_reason',
    'error_code',
    'error_message',
    'lease_owner',
    'locked_at',
    'triggered_by',
    'started_at',
    'finished_at',
    'requeued_at',
])]
class FiscalMonitoringRun extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'trigger' => FiscalTrigger::class,
            'status' => FiscalRunStatus::class,
            'result' => FiscalRunResult::class,
            'situation' => FiscalSituation::class,
            'coverage' => FiscalCoverage::class,
            'mutability' => FiscalMutability::class,
            'source_provenance' => FiscalSourceProvenanceCast::class,
            'verification_state' => FiscalVerificationState::class,
            'attempt' => 'integer',
            'progress' => 'array',
            'items_processed' => 'integer',
            'pages_processed' => 'integer',
            'locked_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'requeued_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FiscalCategory::class, 'fiscal_category_id');
    }

    public function competence(): BelongsTo
    {
        return $this->belongsTo(FiscalCompetence::class, 'competence_id');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringSchedule::class, 'schedule_id');
    }

    public function lastUpdateEvent(): BelongsTo
    {
        return $this->belongsTo(FiscalLastUpdateEvent::class, 'last_update_event_id');
    }

    public function parentRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }

    public function evidenceArtifacts(): HasMany
    {
        return $this->hasMany(FiscalEvidenceArtifact::class, 'run_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(FiscalSnapshot::class, 'run_id');
    }

    public function currentSnapshot(): HasOne
    {
        return $this->hasOne(FiscalSnapshot::class, 'run_id')->where('is_current', true);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(FiscalFinding::class, 'run_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'fiscal_category_id' => $this->fiscal_category_id,
            'competence_id' => $this->competence_id,
            'schedule_id' => $this->schedule_id,
            'system_code' => $this->system_code,
            'service_code' => $this->service_code,
            'operation_code' => $this->operation_code,
            'operation_key' => $this->operation_key,
            'source_provenance' => $this->source_provenance instanceof \BackedEnum
                ? $this->source_provenance->value
                : $this->source_provenance,
            'verification_state' => $this->verification_state instanceof \BackedEnum
                ? $this->verification_state->value
                : $this->verification_state,
            'trigger' => $this->trigger?->value,
            'idempotency_key' => $this->idempotency_key,
            'status' => $this->status?->value,
            'result' => $this->result?->value,
            'situation' => $this->situation?->value,
            'coverage' => $this->coverage?->value,
            'mutability' => $this->mutability?->value,
            'attempt' => $this->attempt,
            'parent_run_id' => $this->parent_run_id,
            'correlation_id' => $this->correlation_id,
            'progress_cursor' => $this->progress_cursor,
            'items_processed' => $this->items_processed,
            'pages_processed' => $this->pages_processed,
            'skip_reason' => $this->skip_reason,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'requeued_at' => $this->requeued_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

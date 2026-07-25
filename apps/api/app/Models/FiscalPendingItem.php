<?php

namespace App\Models;

use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalPendingStatus;
use App\Enums\FiscalSituation;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'snapshot_id',
    'run_id',
    'fiscal_category_id',
    'competence_id',
    'finding_id',
    'code',
    'title',
    'detail',
    'severity',
    'status',
    'situation',
    'due_at',
    'resolved_at',
    'logical_key',
    'open_dedupe_key',
    'metadata',
])]
class FiscalPendingItem extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'severity' => FiscalFindingSeverity::class,
            'status' => FiscalPendingStatus::class,
            'situation' => FiscalSituation::class,
            'due_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(FiscalSnapshot::class, 'snapshot_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'run_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FiscalCategory::class, 'fiscal_category_id');
    }

    public function competence(): BelongsTo
    {
        return $this->belongsTo(FiscalCompetence::class, 'competence_id');
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(FiscalFinding::class, 'finding_id');
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
            'snapshot_id' => $this->snapshot_id,
            'run_id' => $this->run_id,
            'fiscal_category_id' => $this->fiscal_category_id,
            'competence_id' => $this->competence_id,
            'code' => $this->code,
            'title' => $this->title,
            'detail' => $this->detail,
            'severity' => $this->severity?->value,
            'status' => $this->status?->value,
            'situation' => $this->situation?->value,
            'due_at' => $this->due_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'logical_key' => $this->logical_key,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

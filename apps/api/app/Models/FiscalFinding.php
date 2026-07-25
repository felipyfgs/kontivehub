<?php

namespace App\Models;

use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalSituation;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'snapshot_id',
    'run_id',
    'client_id',
    'code',
    'severity',
    'title',
    'detail',
    'situation',
    'is_active',
    'resolved_at',
    'metadata',
])]
class FiscalFinding extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'severity' => FiscalFindingSeverity::class,
            'situation' => FiscalSituation::class,
            'is_active' => 'boolean',
            'resolved_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(FiscalSnapshot::class, 'snapshot_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FiscalMonitoringRun::class, 'run_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'snapshot_id' => $this->snapshot_id,
            'run_id' => $this->run_id,
            'client_id' => $this->client_id,
            'code' => $this->code,
            'severity' => $this->severity?->value,
            'title' => $this->title,
            'detail' => $this->detail,
            'situation' => $this->situation?->value,
            'is_active' => $this->is_active,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

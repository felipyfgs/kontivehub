<?php

namespace App\Models;

use App\Enums\Work\GenerationBatchStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WorkProcessGenerationBatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'work_process_template_id',
    'template_lock_version',
    'competence',
    'reference_period_type',
    'reference_period_start',
    'reference_period_end',
    'status',
    'payload_hash',
    'idempotency_key',
    'request_snapshot',
    'preview_summary',
    'requested_by_membership_id',
    'expires_at',
    'queued_at',
    'completed_at',
])]
class WorkProcessGenerationBatch extends Model
{
    /** @use HasFactory<WorkProcessGenerationBatchFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => GenerationBatchStatus::class,
            'reference_period_start' => 'date',
            'reference_period_end' => 'date',
            'template_lock_version' => 'integer',
            'request_snapshot' => 'array',
            'preview_summary' => 'array',
            'expires_at' => 'immutable_datetime',
            'queued_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkProcessTemplate::class, 'work_process_template_id');
    }

    public function requestedByMembership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'requested_by_membership_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(WorkProcessGenerationItem::class, 'batch_id');
    }

    protected static function newFactory(): WorkProcessGenerationBatchFactory
    {
        return WorkProcessGenerationBatchFactory::new();
    }
}

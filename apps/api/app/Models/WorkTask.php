<?php

namespace App\Models;

use App\Enums\Work\TaskStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WorkTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'work_process_id',
    'sort_order',
    'title',
    'description',
    'status',
    'due_date',
    'target_due_date',
    'work_department_id',
    'assignee_membership_id',
    'is_required',
    'is_critical',
    'requires_evidence',
    'block_reason',
    'lock_version',
    'started_by_membership_id',
    'completed_by_membership_id',
    'started_at',
    'completed_at',
])]
class WorkTask extends Model
{
    /** @use HasFactory<WorkTaskFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'sort_order' => 'integer',
            'due_date' => 'date',
            'target_due_date' => 'date',
            'is_required' => 'boolean',
            'is_critical' => 'boolean',
            'requires_evidence' => 'boolean',
            'lock_version' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(WorkProcess::class, 'work_process_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(WorkDepartment::class, 'work_department_id');
    }

    public function assigneeMembership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'assignee_membership_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WorkComment::class);
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(WorkTaskEvidence::class)
            ->whereNull('removed_at');
    }

    public function allEvidences(): HasMany
    {
        return $this->hasMany(WorkTaskEvidence::class);
    }

    protected static function newFactory(): WorkTaskFactory
    {
        return WorkTaskFactory::new();
    }
}

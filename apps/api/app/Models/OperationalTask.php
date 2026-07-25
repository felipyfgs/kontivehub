<?php

namespace App\Models;

use App\Enums\Work\TaskStatus;
use App\Models\Concerns\BelongsToOffice;
use Database\Factories\OperationalTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id',
    'operational_process_id',
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
class OperationalTask extends Model
{
    /** @use HasFactory<OperationalTaskFactory> */
    use BelongsToOffice, HasFactory;

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
        return $this->belongsTo(OperationalProcess::class, 'operational_process_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(WorkDepartment::class, 'work_department_id');
    }

    public function assigneeMembership(): BelongsTo
    {
        return $this->belongsTo(OfficeMembership::class, 'assignee_membership_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(OperationalComment::class);
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(OperationalTaskEvidence::class)
            ->whereNull('removed_at');
    }

    public function allEvidences(): HasMany
    {
        return $this->hasMany(OperationalTaskEvidence::class);
    }

    protected static function newFactory(): OperationalTaskFactory
    {
        return OperationalTaskFactory::new();
    }
}

<?php

namespace App\Models;

use App\Enums\Work\ProcessOrigin;
use App\Enums\Work\ProcessStatus;
use App\Models\Concerns\BelongsToOffice;
use Database\Factories\OperationalProcessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id',
    'client_id',
    'process_template_id',
    'generation_batch_id',
    'origin',
    'title',
    'description',
    'monitoring_module_key',
    'competence',
    'reference_period_type',
    'reference_period_start',
    'reference_period_end',
    'due_date',
    'target_due_date',
    'subject_to_fine',
    'work_department_id',
    'assignee_membership_id',
    'status',
    'template_snapshot',
    'lock_version',
    'created_by_membership_id',
    'started_at',
    'completed_at',
    'archived_at',
])]
class OperationalProcess extends Model
{
    /** @use HasFactory<OperationalProcessFactory> */
    use BelongsToOffice, HasFactory;

    protected function casts(): array
    {
        return [
            'origin' => ProcessOrigin::class,
            'status' => ProcessStatus::class,
            'reference_period_start' => 'date',
            'reference_period_end' => 'date',
            'due_date' => 'date',
            'target_due_date' => 'date',
            'subject_to_fine' => 'boolean',
            'template_snapshot' => 'array',
            'lock_version' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'archived_at' => 'immutable_datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @param  Builder<OperationalProcess>  $query
     * @return Builder<OperationalProcess>
     */
    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProcessTemplate::class, 'process_template_id');
    }

    public function generationBatch(): BelongsTo
    {
        return $this->belongsTo(ProcessGenerationBatch::class, 'generation_batch_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(WorkDepartment::class, 'work_department_id');
    }

    public function assigneeMembership(): BelongsTo
    {
        return $this->belongsTo(OfficeMembership::class, 'assignee_membership_id');
    }

    public function createdByMembership(): BelongsTo
    {
        return $this->belongsTo(OfficeMembership::class, 'created_by_membership_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(OperationalTask::class)->orderBy('sort_order');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(OperationalComment::class);
    }

    protected static function newFactory(): OperationalProcessFactory
    {
        return OperationalProcessFactory::new();
    }
}

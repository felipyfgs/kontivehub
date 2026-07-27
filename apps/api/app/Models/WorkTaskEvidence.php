<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WorkTaskEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadados de evidência. vault_object_id nunca sai da API.
 */
#[Fillable([
    'tenant_id',
    'work_task_id',
    'original_filename',
    'mime_type',
    'byte_size',
    'sha256',
    'vault_object_id',
    'uploaded_by_membership_id',
    'removal_reason',
    'removed_at',
    'removed_by_membership_id',
])]
#[Hidden(['vault_object_id'])]
class WorkTaskEvidence extends Model
{
    /** @use HasFactory<WorkTaskEvidenceFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'work_task_evidences';

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'removed_at' => 'immutable_datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(WorkTask::class, 'work_task_id');
    }

    public function uploadedByMembership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'uploaded_by_membership_id');
    }

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }

    protected static function newFactory(): WorkTaskEvidenceFactory
    {
        return WorkTaskEvidenceFactory::new();
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Database\Factories\OperationalTaskEvidenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadados de evidência. vault_object_id nunca sai da API.
 */
#[Fillable([
    'office_id',
    'operational_task_id',
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
class OperationalTaskEvidence extends Model
{
    /** @use HasFactory<OperationalTaskEvidenceFactory> */
    use BelongsToOffice, HasFactory;

    protected $table = 'operational_task_evidences';

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'removed_at' => 'immutable_datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(OperationalTask::class, 'operational_task_id');
    }

    public function uploadedByMembership(): BelongsTo
    {
        return $this->belongsTo(OfficeMembership::class, 'uploaded_by_membership_id');
    }

    public function isActive(): bool
    {
        return $this->removed_at === null;
    }

    protected static function newFactory(): OperationalTaskEvidenceFactory
    {
        return OperationalTaskEvidenceFactory::new();
    }
}

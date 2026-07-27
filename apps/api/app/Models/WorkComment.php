<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WorkCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comentário append-only — sem updated_at / edição retroativa.
 */
#[Fillable([
    'tenant_id',
    'work_process_id',
    'work_task_id',
    'author_membership_id',
    'body',
])]
class WorkComment extends Model
{
    /** @use HasFactory<WorkCommentFactory> */
    use BelongsToTenant, HasFactory;

    protected $table = 'work_comments';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(WorkProcess::class, 'work_process_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(WorkTask::class, 'work_task_id');
    }

    public function authorMembership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'author_membership_id');
    }

    protected static function newFactory(): WorkCommentFactory
    {
        return WorkCommentFactory::new();
    }
}

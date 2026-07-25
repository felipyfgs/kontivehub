<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Database\Factories\OperationalCommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comentário append-only — sem updated_at / edição retroativa.
 */
#[Fillable([
    'office_id',
    'operational_process_id',
    'operational_task_id',
    'author_membership_id',
    'body',
])]
class OperationalComment extends Model
{
    /** @use HasFactory<OperationalCommentFactory> */
    use BelongsToOffice, HasFactory;

    protected $table = 'operational_comments';

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    public function process(): BelongsTo
    {
        return $this->belongsTo(OperationalProcess::class, 'operational_process_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(OperationalTask::class, 'operational_task_id');
    }

    public function authorMembership(): BelongsTo
    {
        return $this->belongsTo(OfficeMembership::class, 'author_membership_id');
    }

    protected static function newFactory(): OperationalCommentFactory
    {
        return OperationalCommentFactory::new();
    }
}

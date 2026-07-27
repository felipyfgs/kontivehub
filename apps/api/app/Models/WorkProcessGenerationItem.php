<?php

namespace App\Models;

use App\Enums\Work\GenerationItemStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\WorkProcessGenerationItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'batch_id',
    'client_id',
    'status',
    'is_blocked',
    'preview_payload',
    'alerts',
    'conflicts',
    'created_process_id',
    'error_message',
    'attempt_count',
])]
class WorkProcessGenerationItem extends Model
{
    /** @use HasFactory<WorkProcessGenerationItemFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => GenerationItemStatus::class,
            'is_blocked' => 'boolean',
            'preview_payload' => 'array',
            'alerts' => 'array',
            'conflicts' => 'array',
            'attempt_count' => 'integer',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(WorkProcessGenerationBatch::class, 'batch_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function createdProcess(): BelongsTo
    {
        return $this->belongsTo(WorkProcess::class, 'created_process_id');
    }

    protected static function newFactory(): WorkProcessGenerationItemFactory
    {
        return WorkProcessGenerationItemFactory::new();
    }
}

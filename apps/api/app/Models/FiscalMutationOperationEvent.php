<?php

namespace App\Models;

use App\Enums\FiscalMutationStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'fiscal_mutation_operation_id',
    'from_status',
    'to_status',
    'event',
    'result',
    'correlation_id',
    'actor_user_id',
    'context',
    'created_at',
])]
class FiscalMutationOperationEvent extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'from_status' => FiscalMutationStatus::class,
            'to_status' => FiscalMutationStatus::class,
            'context' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(FiscalMutationOperation::class, 'fiscal_mutation_operation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'operation_id' => $this->fiscal_mutation_operation_id,
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status->value,
            'event' => $this->event,
            'result' => $this->result,
            'correlation_id' => $this->correlation_id,
            'actor_user_id' => $this->actor_user_id,
            'context' => $this->context,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}

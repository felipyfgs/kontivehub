<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Schema preparado para runtime futuro — sem jobs/executor nesta change.
 */
#[Fillable([
    'tenant_id',
    'run_id',
    'conversation_id',
    'event_key',
    'event_digest',
    'consumed_at',
])]
class CommunicationFlowConsumption extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'consumed_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CommunicationFlowRun::class, 'run_id');
    }
}

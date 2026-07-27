<?php

namespace App\Models;

use App\Enums\Communication\FlowRunStepStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'run_id',
    'node_id',
    'node_type',
    'seq',
    'status',
    'effect_key',
    'entered_at',
    'exited_at',
    'result_meta',
])]
class CommunicationFlowRunStep extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'status' => FlowRunStepStatus::class,
            'entered_at' => 'datetime',
            'exited_at' => 'datetime',
            'result_meta' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(CommunicationFlowRun::class, 'run_id');
    }
}

<?php

namespace App\Models;

use App\Enums\Communication\FlowRunStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id',
    'flow_id',
    'flow_version_id',
    'binding_id',
    'conversation_id',
    'status',
    'current_node_id',
    'context_encrypted',
    'started_at',
    'finished_at',
    'waiting_until',
    'waiting_effect_key',
    'waiting_outbox_entry_id',
])]
class CommunicationFlowRun extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'status' => FlowRunStatus::class,
            'context_encrypted' => 'encrypted:array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'waiting_until' => 'datetime',
        ];
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(CommunicationFlow::class, 'flow_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(CommunicationFlowVersion::class, 'flow_version_id');
    }

    public function binding(): BelongsTo
    {
        return $this->belongsTo(CommunicationFlowInboxBinding::class, 'binding_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CommunicationConversation::class, 'conversation_id');
    }

    public function waitingOutboxEntry(): BelongsTo
    {
        return $this->belongsTo(CommunicationOutboxEntry::class, 'waiting_outbox_entry_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(CommunicationFlowRunStep::class, 'run_id');
    }
}

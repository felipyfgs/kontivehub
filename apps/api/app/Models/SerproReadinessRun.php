<?php

namespace App\Models;

use App\Enums\SerproEnvironment;
use App\Enums\SerproReadinessGate;
use App\Enums\SerproReadinessScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'scope',
    'environment',
    'serpro_contract_id',
    'office_id',
    'client_id',
    'operation_key',
    'highest_gate',
    'result',
    'live_evidence',
    'trigger',
    'actor_user_id',
    'started_at',
    'finished_at',
    'expires_at',
    'summary',
])]
class SerproReadinessRun extends Model
{
    protected function casts(): array
    {
        return [
            'scope' => SerproReadinessScope::class,
            'environment' => SerproEnvironment::class,
            'highest_gate' => SerproReadinessGate::class,
            'live_evidence' => 'boolean',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'summary' => 'array',
        ];
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(SerproReadinessEvidence::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'scope' => $this->scope->value,
            'environment' => $this->environment->value,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'operation_key' => $this->operation_key,
            'highest_gate' => $this->highest_gate?->value,
            'result' => $this->result,
            'live_evidence' => $this->live_evidence,
            'trigger' => $this->trigger,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'summary' => $this->summary,
            'evidences' => $this->evidences->map->toSanitizedArray()->all(),
        ];
    }
}

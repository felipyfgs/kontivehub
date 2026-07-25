<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'control_key',
    'control_type',
    'active',
    'source',
    'reason',
    'updated_by_user_id',
    'activated_at',
    'deactivated_at',
    'metadata',
])]
class SerproRuntimeControl extends Model
{
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'activated_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'control_key' => $this->control_key,
            'control_type' => $this->control_type,
            'active' => (bool) $this->active,
            'source' => $this->source,
            'reason' => $this->reason,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

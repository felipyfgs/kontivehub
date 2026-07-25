<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'scope_key',
    'dependency',
    'solution_code',
    'state',
    'failures',
    'half_open_probes',
    'open_until',
    'last_reason',
    'metadata',
])]
class SerproCircuitBreakerState extends Model
{
    protected function casts(): array
    {
        return [
            'open_until' => 'immutable_datetime',
            'metadata' => 'array',
            'failures' => 'integer',
            'half_open_probes' => 'integer',
        ];
    }
}

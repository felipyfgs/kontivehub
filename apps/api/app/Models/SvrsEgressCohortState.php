<?php

namespace App\Models;

use App\Enums\SvrsEgressBlockCause;
use Illuminate\Database\Eloquent\Model;

/**
 * Estado durável do breaker/coorte de egress SVRS (PostgreSQL).
 */
class SvrsEgressCohortState extends Model
{
    protected $table = 'svrs_egress_cohort_states';

    protected $fillable = [
        'cohort_id',
        'state',
        'cause',
        'tier',
        'opened_at',
        'next_probe_at',
        'canary_access_key_hash',
        'canary_key_mask',
        'template_fingerprint',
        'active_deployment_id',
        'last_exchange_at',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'cause' => SvrsEgressBlockCause::class,
            'tier' => 'integer',
            'opened_at' => 'datetime',
            'next_probe_at' => 'datetime',
            'last_exchange_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function isOpen(): bool
    {
        return $this->state === 'open';
    }

    public function isHalfOpen(): bool
    {
        return $this->state === 'half_open';
    }

    public function isClosed(): bool
    {
        return $this->state === 'closed';
    }
}

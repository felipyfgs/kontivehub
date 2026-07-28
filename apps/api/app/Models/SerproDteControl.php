<?php

namespace App\Models;

use App\Enums\SerproDteControlMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'operation_key',
    'mode',
    'pilot_tenant_id',
    'pilot_client_id',
    'limited_max_quantity',
    'limited_used_quantity',
    'cycle_code',
    'promoted_at',
    'promoted_by_user_id',
    'disabled_at',
    'disabled_by_user_id',
    'disable_reason',
    'alert_percent',
    'alert_80_emitted',
    'alert_100_emitted',
    'metadata',
])]
class SerproDteControl extends Model
{
    protected function casts(): array
    {
        return [
            'mode' => SerproDteControlMode::class,
            'promoted_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'alert_80_emitted' => 'boolean',
            'alert_100_emitted' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function pilotTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'pilot_tenant_id');
    }

    public function pilotClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'pilot_client_id');
    }

    public function allowsTenant(int $tenantId): bool
    {
        if ($this->mode === SerproDteControlMode::Disabled) {
            return false;
        }

        return $this->pilot_tenant_id !== null
            && (int) $this->pilot_tenant_id === $tenantId;
    }

    public function remainingLimitedQuantity(): ?int
    {
        if ($this->mode !== SerproDteControlMode::Limited) {
            return null;
        }

        $max = (int) ($this->limited_max_quantity ?? 0);
        $used = (int) $this->limited_used_quantity;

        return max(0, $max - $used);
    }

    public function usageRatio(): ?float
    {
        if ($this->mode !== SerproDteControlMode::Limited) {
            return null;
        }

        $max = (int) ($this->limited_max_quantity ?? 0);
        if ($max <= 0) {
            return null;
        }

        return (int) $this->limited_used_quantity / $max;
    }
}

<?php

namespace App\Models;

use App\Enums\SerproDteControlMode;
use App\Support\Serpro\DteCanaryCoordinates;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'operation_key',
    'mode',
    'pilot_office_id',
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

    public function pilotOffice(): BelongsTo
    {
        return $this->belongsTo(Office::class, 'pilot_office_id');
    }

    public function pilotClient(): BelongsTo
    {
        return $this->belongsTo(Client::class, 'pilot_client_id');
    }

    public function allowsOffice(int $officeId): bool
    {
        if ($this->mode === SerproDteControlMode::Disabled) {
            return false;
        }

        return $this->pilot_office_id !== null
            && (int) $this->pilot_office_id === $officeId;
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

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'operation_key' => $this->operation_key ?? DteCanaryCoordinates::OPERATION_KEY,
            'mode' => $this->mode instanceof SerproDteControlMode
                ? $this->mode->value
                : (string) $this->mode,
            'pilot_office_id' => $this->pilot_office_id,
            'pilot_client_id' => $this->pilot_client_id,
            'limited_max_quantity' => $this->limited_max_quantity !== null
                ? (int) $this->limited_max_quantity
                : null,
            'limited_used_quantity' => (int) $this->limited_used_quantity,
            'remaining_quantity' => $this->remainingLimitedQuantity(),
            'usage_ratio' => $this->usageRatio(),
            'cycle_code' => $this->cycle_code,
            'promoted_at' => $this->promoted_at?->toIso8601String(),
            'disabled_at' => $this->disabled_at?->toIso8601String(),
            'disable_reason' => $this->disable_reason,
            'alert_percent' => (int) ($this->alert_percent ?? DteCanaryCoordinates::ALERT_PERCENT),
            'alert_80_emitted' => (bool) $this->alert_80_emitted,
            'alert_100_emitted' => (bool) $this->alert_100_emitted,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

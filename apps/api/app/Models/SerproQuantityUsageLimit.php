<?php

namespace App\Models;

use App\Enums\SerproEnvironment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'environment',
    'cycle_start_day',
    'alert_percent',
    'global_limit_quantity',
    'is_active',
    'updated_by_user_id',
    'metadata',
])]
class SerproQuantityUsageLimit extends Model
{
    protected function casts(): array
    {
        return [
            'environment' => SerproEnvironment::class,
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function isConfiguredPositive(): bool
    {
        return $this->is_active
            && $this->global_limit_quantity !== null
            && (int) $this->global_limit_quantity > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'environment' => $this->environment->value,
            'cycle_start_day' => (int) $this->cycle_start_day,
            'alert_percent' => (int) $this->alert_percent,
            'global_limit_quantity' => $this->global_limit_quantity !== null
                ? (int) $this->global_limit_quantity
                : null,
            'is_active' => (bool) $this->is_active,
            'is_configured_positive' => $this->isConfiguredPositive(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

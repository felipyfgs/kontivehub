<?php

namespace App\Models;

use App\Enums\SerproEnvironment;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'environment',
    'limit_quantity',
    'is_active',
    'updated_by_user_id',
    'metadata',
])]
class SerproOfficeQuantityUsageLimit extends Model
{
    protected function casts(): array
    {
        return [
            'environment' => SerproEnvironment::class,
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    public function isConfiguredPositive(): bool
    {
        return $this->is_active
            && $this->limit_quantity !== null
            && (int) $this->limit_quantity > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'environment' => $this->environment->value,
            'limit_quantity' => $this->limit_quantity !== null
                ? (int) $this->limit_quantity
                : null,
            'is_active' => (bool) $this->is_active,
            'is_configured_positive' => $this->isConfiguredPositive(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}

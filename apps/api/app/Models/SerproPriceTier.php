<?php

namespace App\Models;

use App\Enums\SerproConsumptionClass;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Faixa de preço configurável (sem hardcode no client HTTP).
 */
#[Fillable([
    'price_version_id',
    'consumption_class',
    'system_code',
    'service_code',
    'operation_code',
    'min_quantity',
    'max_quantity',
    'unit_cost_micros',
    'sort_order',
])]
class SerproPriceTier extends Model
{
    protected function casts(): array
    {
        return [
            'consumption_class' => SerproConsumptionClass::class,
            'min_quantity' => 'integer',
            'max_quantity' => 'integer',
            'unit_cost_micros' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function priceVersion(): BelongsTo
    {
        return $this->belongsTo(SerproPriceVersion::class, 'price_version_id');
    }

    public function matchesQuantity(int $quantity): bool
    {
        if ($quantity < $this->min_quantity) {
            return false;
        }

        if ($this->max_quantity !== null && $quantity > $this->max_quantity) {
            return false;
        }

        return true;
    }
}

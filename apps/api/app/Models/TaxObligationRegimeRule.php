<?php

namespace App\Models;

use App\Enums\TaxObligationApplicability;
use App\Enums\TaxRegimeCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'obligation_version_id',
    'tax_regime',
    'applicability',
    'rule_basis',
    'priority',
    'metadata',
])]
class TaxObligationRegimeRule extends Model
{
    protected function casts(): array
    {
        return [
            'tax_regime' => TaxRegimeCode::class,
            'applicability' => TaxObligationApplicability::class,
            'priority' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(TaxObligationVersion::class, 'obligation_version_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'obligation_version_id' => $this->obligation_version_id,
            'tax_regime' => $this->tax_regime?->value,
            'applicability' => $this->applicability?->value,
            'rule_basis' => $this->rule_basis,
            'priority' => $this->priority,
        ];
    }
}

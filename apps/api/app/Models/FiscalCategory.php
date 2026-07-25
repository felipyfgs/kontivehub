<?php

namespace App\Models;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Catálogo tipado global de categorias fiscais (sem office_id).
 * Vínculos por tenant vivem em OfficeFiscalCategoryLink.
 */
#[Fillable([
    'code',
    'name',
    'module_key',
    'default_coverage',
    'default_mutability',
    'system_code',
    'service_code',
    'is_active',
    'sort_order',
    'description',
    'metadata',
])]
class FiscalCategory extends Model
{
    protected function casts(): array
    {
        return [
            'default_coverage' => FiscalCoverage::class,
            'default_mutability' => FiscalMutability::class,
            'is_active' => 'boolean',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function links(): HasMany
    {
        return $this->hasMany(OfficeFiscalCategoryLink::class, 'fiscal_category_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'module_key' => $this->module_key,
            'default_coverage' => $this->default_coverage?->value,
            'default_mutability' => $this->default_mutability?->value,
            'system_code' => $this->system_code,
            'service_code' => $this->service_code,
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'description' => $this->description,
        ];
    }
}

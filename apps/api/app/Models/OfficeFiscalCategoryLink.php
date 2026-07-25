<?php

namespace App\Models;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalLinkStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id',
    'client_id',
    'fiscal_category_id',
    'status',
    'coverage',
    'activated_at',
    'deactivated_at',
    'notes',
    'created_by',
])]
class OfficeFiscalCategoryLink extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'status' => FiscalLinkStatus::class,
            'coverage' => FiscalCoverage::class,
            'activated_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FiscalCategory::class, 'fiscal_category_id');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(FiscalMonitoringSchedule::class, 'category_link_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'fiscal_category_id' => $this->fiscal_category_id,
            'category_code' => $this->category?->code,
            'category_name' => $this->category?->name,
            'status' => $this->status?->value,
            'coverage' => $this->coverage?->value,
            'activated_at' => $this->activated_at?->toIso8601String(),
            'deactivated_at' => $this->deactivated_at?->toIso8601String(),
            'notes' => $this->notes,
        ];
    }
}

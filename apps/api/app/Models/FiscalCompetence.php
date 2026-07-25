<?php

namespace App\Models;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalSituation;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id',
    'client_id',
    'fiscal_category_id',
    'period_key',
    'period_year',
    'period_month',
    'situation',
    'coverage',
    'due_at',
    'closed_at',
    'metadata',
])]
class FiscalCompetence extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'situation' => FiscalSituation::class,
            'coverage' => FiscalCoverage::class,
            'period_year' => 'integer',
            'period_month' => 'integer',
            'due_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'metadata' => 'array',
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

    public function runs(): HasMany
    {
        return $this->hasMany(FiscalMonitoringRun::class, 'competence_id');
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
            'period_key' => $this->period_key,
            'period_year' => $this->period_year,
            'period_month' => $this->period_month,
            'situation' => $this->situation?->value,
            'coverage' => $this->coverage?->value,
            'due_at' => $this->due_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
        ];
    }
}

<?php

namespace App\Models;

use App\Enums\TaxPeriodGranularity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'calendar_version_id',
    'obligation_definition_id',
    'period_granularity',
    'due_day',
    'due_month_offset',
    'fixed_due_month',
    'fixed_due_day',
    'business_day_adjustment',
    'timezone',
    'metadata',
])]
class TaxDeadlineRule extends Model
{
    protected function casts(): array
    {
        return [
            'period_granularity' => TaxPeriodGranularity::class,
            'due_day' => 'integer',
            'due_month_offset' => 'integer',
            'fixed_due_month' => 'integer',
            'fixed_due_day' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function calendar(): BelongsTo
    {
        return $this->belongsTo(TaxDeadlineCalendarVersion::class, 'calendar_version_id');
    }

    public function obligation(): BelongsTo
    {
        return $this->belongsTo(TaxObligationDefinition::class, 'obligation_definition_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'calendar_version_id' => $this->calendar_version_id,
            'obligation_definition_id' => $this->obligation_definition_id,
            'period_granularity' => $this->period_granularity?->value,
            'due_day' => $this->due_day,
            'due_month_offset' => $this->due_month_offset,
            'fixed_due_month' => $this->fixed_due_month,
            'fixed_due_day' => $this->fixed_due_day,
            'business_day_adjustment' => $this->business_day_adjustment,
            'timezone' => $this->timezone,
        ];
    }
}

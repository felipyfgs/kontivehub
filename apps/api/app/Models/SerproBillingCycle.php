<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

#[Fillable([
    'cycle_code',
    'period_start',
    'period_end',
    'label',
    'status',
    'metadata',
])]
class SerproBillingCycle extends Model
{
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'metadata' => 'array',
        ];
    }

    public function contains(Carbon|string $at): bool
    {
        $at = $at instanceof Carbon ? $at->copy()->startOfDay() : Carbon::parse($at)->startOfDay();

        $start = Carbon::parse($this->period_start)->startOfDay();
        $end = Carbon::parse($this->period_end)->endOfDay();

        return $at->gte($start) && $at->lte($end);
    }
}

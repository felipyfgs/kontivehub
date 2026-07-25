<?php

namespace App\Models;

use App\Enums\FiscalRunResult;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id',
    'client_id',
    'fiscal_category_id',
    'category_link_id',
    'system_code',
    'service_code',
    'operation_code',
    'is_enabled',
    'interval_minutes',
    'preferred_minute',
    'next_run_at',
    'last_run_at',
    'last_success_at',
    'last_result',
    'last_skip_reason',
    'metadata',
])]
class FiscalMonitoringSchedule extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'interval_minutes' => 'integer',
            'preferred_minute' => 'integer',
            'next_run_at' => 'immutable_datetime',
            'last_run_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'last_result' => FiscalRunResult::class,
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

    public function categoryLink(): BelongsTo
    {
        return $this->belongsTo(OfficeFiscalCategoryLink::class, 'category_link_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(FiscalMonitoringRun::class, 'schedule_id');
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
            'system_code' => $this->system_code,
            'service_code' => $this->service_code,
            'operation_code' => $this->operation_code,
            'is_enabled' => $this->is_enabled,
            'interval_minutes' => $this->interval_minutes,
            'preferred_minute' => $this->preferred_minute,
            'next_run_at' => $this->next_run_at?->toIso8601String(),
            'last_run_at' => $this->last_run_at?->toIso8601String(),
            'last_success_at' => $this->last_success_at?->toIso8601String(),
            'last_result' => $this->last_result?->value,
            'last_skip_reason' => $this->last_skip_reason,
        ];
    }
}

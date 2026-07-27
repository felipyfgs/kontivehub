<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\FiscalMonitoring\MonitorScheduleDayHasher;
use Database\Factories\TenantMonitorSchedulePolicyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Política mensal de dia de execução automática (1–28) por tenant + monitor.
 */
#[Fillable([
    'tenant_id',
    'monitor_key',
    'day_of_month',
    'is_custom',
    'timezone',
    'updated_by_user_id',
])]
class TenantMonitorSchedulePolicy extends Model
{
    /** @use HasFactory<TenantMonitorSchedulePolicyFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'day_of_month' => 'integer',
            'is_custom' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $policy): void {
            $day = (int) $policy->day_of_month;
            if ($day < 1 || $day > 28) {
                throw new InvalidArgumentException(
                    'day_of_month da política de monitor deve estar entre 1 e 28.'
                );
            }
        });
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    /**
     * Resolve política existente ou materializa default hash (sem persistir).
     */
    public static function resolveDay(int $tenantId, string $monitorKey, ?self $existing = null): int
    {
        if ($existing !== null) {
            return $existing->day_of_month;
        }

        return MonitorScheduleDayHasher::defaultDay($tenantId, $monitorKey);
    }

    /**
     * Cria ou atualiza política customizada (dia 1–28).
     */
    public static function setCustomDay(
        int $tenantId,
        string $monitorKey,
        int $dayOfMonth,
        ?int $updatedByUserId = null,
        ?string $timezone = null,
    ): self {
        if ($dayOfMonth < 1 || $dayOfMonth > 28) {
            throw new InvalidArgumentException(
                'day_of_month da política de monitor deve estar entre 1 e 28.'
            );
        }

        /** @var self $policy */
        $policy = static::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'monitor_key' => $monitorKey,
            ],
            [
                'day_of_month' => $dayOfMonth,
                'is_custom' => true,
                'timezone' => $timezone,
                'updated_by_user_id' => $updatedByUserId,
            ],
        );

        return $policy;
    }

    /**
     * Garante registro com dia default determinístico se ainda não existir.
     */
    public static function ensureDefault(int $tenantId, string $monitorKey): self
    {
        $existing = static::query()
            ->where('tenant_id', $tenantId)
            ->where('monitor_key', $monitorKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return static::query()->create([
            'tenant_id' => $tenantId,
            'monitor_key' => $monitorKey,
            'day_of_month' => MonitorScheduleDayHasher::defaultDay($tenantId, $monitorKey),
            'is_custom' => false,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'monitor_key' => $this->monitor_key,
            'day_of_month' => $this->day_of_month,
            'is_custom' => $this->is_custom,
            'timezone' => $this->timezone,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    protected static function newFactory(): TenantMonitorSchedulePolicyFactory
    {
        return TenantMonitorSchedulePolicyFactory::new();
    }
}

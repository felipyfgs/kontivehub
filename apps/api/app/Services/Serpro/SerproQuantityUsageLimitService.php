<?php

namespace App\Services\Serpro;

use App\Enums\SerproEnvironment;
use App\Enums\SerproUsageReservationStatus;
use App\Models\SerproApiUsageEntry;
use App\Models\SerproApiUsageReservation;
use App\Models\SerproQuantityUsageLimit;
use App\Models\SerproTenantQuantityUsageLimit;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Limites quantitativos por ambiente/Tenant sobre o ledger local.
 * null / zero / ausente = fail-closed (nunca ilimitado).
 */
final class SerproQuantityUsageLimitService
{
    public const BLOCK_NOT_CONFIGURED = 'QUANTITY_LIMIT_NOT_CONFIGURED';

    public const BLOCK_GLOBAL = 'QUANTITY_GLOBAL_EXCEEDED';

    public const BLOCK_TENANT = 'QUANTITY_TENANT_EXCEEDED';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function getOrDefault(SerproEnvironment $environment): SerproQuantityUsageLimit
    {
        return SerproQuantityUsageLimit::query()->firstOrCreate(
            ['environment' => $environment->value],
            [
                'cycle_start_day' => 1,
                'alert_percent' => 80,
                'global_limit_quantity' => null,
                'is_active' => true,
            ],
        );
    }

    /**
     * @param  list<array{tenant_id: int, limit_quantity: int|null}>  $tenantLimits
     */
    public function upsert(
        SerproEnvironment $environment,
        int $cycleStartDay,
        int $alertPercent,
        ?int $globalLimitQuantity,
        array $tenantLimits = [],
        ?int $actorUserId = null,
    ): SerproQuantityUsageLimit {
        if ($cycleStartDay < 1 || $cycleStartDay > 28) {
            throw new RuntimeException('Dia inicial do ciclo deve estar entre 1 e 28.');
        }

        if ($alertPercent < 1 || $alertPercent > 100) {
            throw new RuntimeException('Alerta percentual deve estar entre 1 e 100.');
        }

        if ($globalLimitQuantity !== null && $globalLimitQuantity <= 0) {
            throw new RuntimeException('Limite global deve ser positivo ou nulo (bloqueante).');
        }

        return DB::transaction(function () use (
            $environment,
            $cycleStartDay,
            $alertPercent,
            $globalLimitQuantity,
            $tenantLimits,
            $actorUserId,
        ): SerproQuantityUsageLimit {
            $row = $this->getOrDefault($environment);
            $row->forceFill([
                'cycle_start_day' => $cycleStartDay,
                'alert_percent' => $alertPercent,
                'global_limit_quantity' => $globalLimitQuantity,
                'is_active' => true,
                'updated_by_user_id' => $actorUserId,
            ])->save();

            foreach ($tenantLimits as $item) {
                $tenantId = (int) ($item['tenant_id'] ?? 0);
                if ($tenantId <= 0) {
                    throw new RuntimeException('tenant_id inválido em limites por Tenant.');
                }

                $limit = $item['limit_quantity'] ?? null;
                if ($limit !== null && (int) $limit <= 0) {
                    throw new RuntimeException('Limite por Tenant deve ser positivo ou nulo (bloqueante).');
                }

                SerproTenantQuantityUsageLimit::query()->updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'environment' => $environment->value,
                    ],
                    [
                        'limit_quantity' => $limit,
                        'is_active' => true,
                        'updated_by_user_id' => $actorUserId,
                    ],
                );
            }

            $this->audit->record('serpro.quantity_limits.upsert', 'SUCCESS', $row, [
                'environment' => $environment->value,
                'cycle_start_day' => $cycleStartDay,
                'alert_percent' => $alertPercent,
                'global_limit_quantity' => $globalLimitQuantity,
                'tenant_limits_count' => count($tenantLimits),
            ], $actorUserId, null);

            return $row->refresh();
        });
    }

    /**
     * @return array{
     *   allowed: bool,
     *   block_reason: string|null,
     *   alert_reached: bool,
     *   cycle_code: string,
     *   period_start: string,
     *   period_end: string,
     *   used_quantity: int,
     *   reserved_quantity: int,
     *   projected: int,
     *   global_limit: int|null,
     *   tenant_limit: int|null,
     *   applicable_limit: int|null,
     *   ratio: float|null,
     *   alert_percent: int
     * }
     */
    public function evaluate(
        SerproEnvironment $environment,
        ?int $tenantId,
        int $reserveQuantity = 1,
        Carbon|string|null $at = null,
    ): array {
        $at = $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : now());
        $config = $this->getOrDefault($environment);
        $cycle = $this->resolveCycle((int) $config->cycle_start_day, $at);

        $usedGlobal = $this->billableQuantity(null, $environment, $cycle['period_start'], $cycle['period_end']);
        $reservedGlobal = $this->openReservedQuantity(null, $environment, $cycle['period_start'], $cycle['period_end']);
        $usedTenant = $tenantId !== null
            ? $this->billableQuantity($tenantId, $environment, $cycle['period_start'], $cycle['period_end'])
            : 0;
        $reservedTenant = $tenantId !== null
            ? $this->openReservedQuantity($tenantId, $environment, $cycle['period_start'], $cycle['period_end'])
            : 0;

        $globalLimit = $config->isConfiguredPositive() ? (int) $config->global_limit_quantity : null;
        $tenantLimit = null;
        if ($tenantId !== null) {
            $tenantRow = SerproTenantQuantityUsageLimit::query()
                ->where('tenant_id', $tenantId)
                ->where('environment', $environment->value)
                ->where('is_active', true)
                ->first();
            $tenantLimit = ($tenantRow !== null && $tenantRow->isConfiguredPositive())
                ? (int) $tenantRow->limit_quantity
                : null;
        }

        $qty = max(0, $reserveQuantity);
        $projectedGlobal = $usedGlobal + $reservedGlobal + $qty;
        $projectedTenant = $usedTenant + $reservedTenant + $qty;

        $blockReason = null;
        if ($globalLimit === null) {
            $blockReason = self::BLOCK_NOT_CONFIGURED;
        } elseif ($projectedGlobal > $globalLimit) {
            $blockReason = self::BLOCK_GLOBAL;
        } elseif ($tenantId !== null && $tenantLimit === null) {
            $blockReason = self::BLOCK_NOT_CONFIGURED;
        } elseif ($tenantId !== null && $tenantLimit !== null && $projectedTenant > $tenantLimit) {
            $blockReason = self::BLOCK_TENANT;
        }

        $applicable = $globalLimit;
        if ($tenantLimit !== null) {
            $applicable = $applicable === null ? $tenantLimit : min($applicable, $tenantLimit);
        }

        $projected = $tenantId !== null
            ? min($projectedGlobal, $projectedTenant)
            : $projectedGlobal;

        $ratio = ($applicable !== null && $applicable > 0)
            ? (($tenantId !== null ? ($usedTenant + $reservedTenant) : ($usedGlobal + $reservedGlobal)) / $applicable)
            : null;

        $alertPercent = (int) $config->alert_percent;
        $alertReached = $ratio !== null && $ratio >= ($alertPercent / 100);

        return [
            'allowed' => $blockReason === null,
            'block_reason' => $blockReason,
            'alert_reached' => $alertReached,
            'cycle_code' => $cycle['cycle_code'],
            'period_start' => $cycle['period_start']->toIso8601String(),
            'period_end' => $cycle['period_end']->toIso8601String(),
            'used_quantity' => $tenantId !== null ? $usedTenant : $usedGlobal,
            'reserved_quantity' => $tenantId !== null ? $reservedTenant : $reservedGlobal,
            'projected' => $projected,
            'global_limit' => $globalLimit,
            'tenant_limit' => $tenantLimit,
            'applicable_limit' => $applicable,
            'ratio' => $ratio,
            'alert_percent' => $alertPercent,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listTenantLimits(SerproEnvironment $environment): array
    {
        return SerproTenantQuantityUsageLimit::query()
            ->where('environment', $environment->value)
            ->orderBy('tenant_id')
            ->get()
            ->map->toSanitizedArray()
            ->all();
    }

    /**
     * @return array{cycle_code: string, period_start: Carbon, period_end: Carbon}
     */
    public function resolveCycle(int $cycleStartDay, Carbon $at): array
    {
        $day = max(1, min(28, $cycleStartDay));
        $local = $at->copy()->timezone('America/Sao_Paulo');

        if ($local->day >= $day) {
            $start = $local->copy()->startOfMonth()->day($day)->startOfDay();
            $end = $local->copy()->addMonthNoOverflow()->startOfMonth()->day(min($day, 28))->subDay()->endOfDay();
            // ciclo [start_day, start_day-1 do próximo mês]
            if ($day === 1) {
                $end = $local->copy()->endOfMonth()->endOfDay();
            } else {
                $end = $local->copy()->addMonthNoOverflow()->startOfMonth()->day($day)->subDay()->endOfDay();
            }
        } else {
            $start = $local->copy()->subMonthNoOverflow()->startOfMonth()->day($day)->startOfDay();
            if ($day === 1) {
                $end = $local->copy()->subMonthNoOverflow()->endOfMonth()->endOfDay();
            } else {
                $end = $local->copy()->startOfMonth()->day($day)->subDay()->endOfDay();
            }
        }

        return [
            'cycle_code' => sprintf('QTY_%s_%s', $start->format('Ymd'), $end->format('Ymd')),
            'period_start' => $start,
            'period_end' => $end,
        ];
    }

    private function billableQuantity(
        ?int $tenantId,
        SerproEnvironment $environment,
        Carbon $from,
        Carbon $to,
    ): int {
        $q = SerproApiUsageEntry::query()
            ->where('environment', $environment->value)
            ->whereBetween('occurred_at', [$from, $to]);

        if ($tenantId !== null) {
            $q->where('tenant_id', $tenantId);
        }

        return (int) $q->sum('quantity');
    }

    private function openReservedQuantity(
        ?int $tenantId,
        SerproEnvironment $environment,
        Carbon $from,
        Carbon $to,
    ): int {
        $q = SerproApiUsageReservation::query()
            ->where('status', SerproUsageReservationStatus::Reserved->value)
            ->where('environment', $environment->value)
            ->whereBetween('created_at', [$from, $to]);

        if ($tenantId !== null) {
            $q->where('tenant_id', $tenantId);
        }

        return (int) $q->sum('quantity');
    }
}

<?php

namespace App\Services\Serpro;

use App\Enums\SerproEnvironment;
use App\Enums\SerproUsageReservationStatus;
use App\Models\SerproApiUsageEntry;
use App\Models\SerproApiUsageReservation;
use App\Models\SerproOfficeQuantityUsageLimit;
use App\Models\SerproQuantityUsageLimit;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Limites quantitativos por ambiente/Office sobre o ledger local.
 * null / zero / ausente = fail-closed (nunca ilimitado).
 */
final class SerproQuantityUsageLimitService
{
    public const BLOCK_NOT_CONFIGURED = 'QUANTITY_LIMIT_NOT_CONFIGURED';

    public const BLOCK_GLOBAL = 'QUANTITY_GLOBAL_EXCEEDED';

    public const BLOCK_OFFICE = 'QUANTITY_OFFICE_EXCEEDED';

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
     * @param  list<array{office_id: int, limit_quantity: int|null}>  $officeLimits
     */
    public function upsert(
        SerproEnvironment $environment,
        int $cycleStartDay,
        int $alertPercent,
        ?int $globalLimitQuantity,
        array $officeLimits = [],
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
            $officeLimits,
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

            foreach ($officeLimits as $item) {
                $officeId = (int) ($item['office_id'] ?? 0);
                if ($officeId <= 0) {
                    throw new RuntimeException('office_id inválido em limites por Office.');
                }

                $limit = $item['limit_quantity'] ?? null;
                if ($limit !== null && (int) $limit <= 0) {
                    throw new RuntimeException('Limite por Office deve ser positivo ou nulo (bloqueante).');
                }

                SerproOfficeQuantityUsageLimit::query()->updateOrCreate(
                    [
                        'office_id' => $officeId,
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
                'office_limits_count' => count($officeLimits),
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
     *   office_limit: int|null,
     *   applicable_limit: int|null,
     *   ratio: float|null,
     *   alert_percent: int
     * }
     */
    public function evaluate(
        SerproEnvironment $environment,
        ?int $officeId,
        int $reserveQuantity = 1,
        Carbon|string|null $at = null,
    ): array {
        $at = $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : now());
        $config = $this->getOrDefault($environment);
        $cycle = $this->resolveCycle((int) $config->cycle_start_day, $at);

        $usedGlobal = $this->billableQuantity(null, $environment, $cycle['period_start'], $cycle['period_end']);
        $reservedGlobal = $this->openReservedQuantity(null, $environment, $cycle['period_start'], $cycle['period_end']);
        $usedOffice = $officeId !== null
            ? $this->billableQuantity($officeId, $environment, $cycle['period_start'], $cycle['period_end'])
            : 0;
        $reservedOffice = $officeId !== null
            ? $this->openReservedQuantity($officeId, $environment, $cycle['period_start'], $cycle['period_end'])
            : 0;

        $globalLimit = $config->isConfiguredPositive() ? (int) $config->global_limit_quantity : null;
        $officeLimit = null;
        if ($officeId !== null) {
            $officeRow = SerproOfficeQuantityUsageLimit::query()
                ->where('office_id', $officeId)
                ->where('environment', $environment->value)
                ->where('is_active', true)
                ->first();
            $officeLimit = ($officeRow !== null && $officeRow->isConfiguredPositive())
                ? (int) $officeRow->limit_quantity
                : null;
        }

        $qty = max(0, $reserveQuantity);
        $projectedGlobal = $usedGlobal + $reservedGlobal + $qty;
        $projectedOffice = $usedOffice + $reservedOffice + $qty;

        $blockReason = null;
        if ($globalLimit === null) {
            $blockReason = self::BLOCK_NOT_CONFIGURED;
        } elseif ($projectedGlobal > $globalLimit) {
            $blockReason = self::BLOCK_GLOBAL;
        } elseif ($officeId !== null && $officeLimit === null) {
            $blockReason = self::BLOCK_NOT_CONFIGURED;
        } elseif ($officeId !== null && $officeLimit !== null && $projectedOffice > $officeLimit) {
            $blockReason = self::BLOCK_OFFICE;
        }

        $applicable = $globalLimit;
        if ($officeLimit !== null) {
            $applicable = $applicable === null ? $officeLimit : min($applicable, $officeLimit);
        }

        $projected = $officeId !== null
            ? min($projectedGlobal, $projectedOffice)
            : $projectedGlobal;

        $ratio = ($applicable !== null && $applicable > 0)
            ? (($officeId !== null ? ($usedOffice + $reservedOffice) : ($usedGlobal + $reservedGlobal)) / $applicable)
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
            'used_quantity' => $officeId !== null ? $usedOffice : $usedGlobal,
            'reserved_quantity' => $officeId !== null ? $reservedOffice : $reservedGlobal,
            'projected' => $projected,
            'global_limit' => $globalLimit,
            'office_limit' => $officeLimit,
            'applicable_limit' => $applicable,
            'ratio' => $ratio,
            'alert_percent' => $alertPercent,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOfficeLimits(SerproEnvironment $environment): array
    {
        return SerproOfficeQuantityUsageLimit::query()
            ->where('environment', $environment->value)
            ->orderBy('office_id')
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
        ?int $officeId,
        SerproEnvironment $environment,
        Carbon $from,
        Carbon $to,
    ): int {
        if (! class_exists(SerproApiUsageEntry::class)) {
            return 0;
        }

        $q = SerproApiUsageEntry::query()
            ->whereBetween('occurred_at', [$from, $to]);

        if ($officeId !== null) {
            $q->where('office_id', $officeId);
        }

        // environment column may not exist on all ledger shapes; filter when present.
        if (Schema::hasColumn('serpro_api_usage_entries', 'environment')) {
            $q->where('environment', $environment->value);
        }

        return (int) $q->sum('quantity');
    }

    private function openReservedQuantity(
        ?int $officeId,
        SerproEnvironment $environment,
        Carbon $from,
        Carbon $to,
    ): int {
        if (! class_exists(SerproApiUsageReservation::class)) {
            return 0;
        }

        $q = SerproApiUsageReservation::query()
            ->where('status', SerproUsageReservationStatus::Reserved->value)
            ->whereBetween('created_at', [$from, $to]);

        if ($officeId !== null) {
            $q->where('office_id', $officeId);
        }

        if (Schema::hasColumn('serpro_api_usage_reservations', 'environment')) {
            $q->where('environment', $environment->value);
        }

        return (int) $q->sum('quantity');
    }
}

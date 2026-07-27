<?php

namespace App\Services\Serpro\Usage;

use App\Enums\SerproConsumptionClass;
use App\Enums\SerproUsageReservationStatus;
use App\Models\SerproApiUsageEntry;
use App\Models\SerproApiUsageReservation;
use App\Models\SerproUsageBudget;
use App\Models\TenantSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Franquia por tenant e budgets monetários atômicos.
 *
 * Produção efetiva exige budgets monetários positivos (global, Tenant, canário).
 * null/zero NÃO significam ilimitado no modo produtivo.
 */
final class UsageBudgetGate
{
    public const BLOCK_FRANCHISE = 'FRANCHISE_EXCEEDED';

    public const BLOCK_MONETARY_GLOBAL = 'MONETARY_GLOBAL_BUDGET';

    public const BLOCK_MONETARY_TENANT = 'MONETARY_TENANT_BUDGET';

    public const BLOCK_MONETARY_CANARY = 'MONETARY_CANARY_BUDGET';

    public const BLOCK_MONETARY_OPERATION = 'MONETARY_OPERATION_BUDGET';

    public const BLOCK_BUDGET_NOT_CONFIGURED = 'BUDGET_NOT_CONFIGURED';

    public const BLOCK_PRICE_UNKNOWN = 'PRICE_UNKNOWN';

    public const SCOPE_GLOBAL = 'GLOBAL';

    public const SCOPE_TENANT = 'TENANT';

    public const SCOPE_OPERATION = 'OPERATION';

    public function __construct(
        private readonly UsageShadowPolicy $shadow,
        private readonly BillingCycleResolver $cycles,
    ) {}

    /**
     * @return array{
     *     allowed: bool,
     *     would_block: bool,
     *     block_reason: string|null,
     *     period_year: int,
     *     period_month: int,
     *     cycle_code: string,
     *     used_quantity: int,
     *     reserved_open_quantity: int,
     *     franchise_quota: int|null,
     *     remaining: int|null,
     *     franchise_ratio: float|null,
     *     alert_threshold_reached: bool,
     *     monetary: array<string, mixed>
     * }
     */
    public function evaluate(
        int $tenantId,
        SerproConsumptionClass $class,
        int $quantity,
        bool $isEssential,
        Carbon|string|null $at = null,
        int $estimatedCostMicros = 0,
        bool $isCanary = false,
        ?string $operationKey = null,
        ?string $environment = 'PRODUCTION',
    ): array {
        $at = $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : now());
        $cycle = $this->cycles->resolve($at);
        $year = (int) $at->year;
        $month = (int) $at->month;

        $used = $this->billableQuantityForTenant($tenantId, $cycle['period_start'], $cycle['period_end']);
        $openReserved = $this->openReservedQuantityForTenant($tenantId, $cycle['period_start'], $cycle['period_end']);
        $projected = $used + $openReserved + max(0, $quantity);

        $franchise = $this->franchiseQuota($tenantId);
        $remaining = $franchise === null ? null : max(0, $franchise - $used - $openReserved);
        $ratio = ($franchise !== null && $franchise > 0)
            ? ($used + $openReserved) / $franchise
            : null;

        $alertThreshold = (float) config('serpro_usage.franchise_alert_threshold', 0.8);
        $alertReached = $ratio !== null && $ratio >= $alertThreshold;

        $cost = max(0, $estimatedCostMicros);
        $monetary = $this->evaluateMonetary(
            tenantId: $tenantId,
            costMicros: $cost,
            isCanary: $isCanary,
            operationKey: $operationKey,
            environment: $environment ?? 'PRODUCTION',
            cycleCode: $cycle['cycle_code'],
            at: $at,
        );

        $blockReason = null;

        if ($class === SerproConsumptionClass::NaoFaturavel) {
            return $this->result(
                allowed: true,
                wouldBlock: false,
                blockReason: null,
                year: $year,
                month: $month,
                cycleCode: $cycle['cycle_code'],
                used: $used,
                openReserved: $openReserved,
                franchise: $franchise,
                remaining: $remaining,
                ratio: $ratio,
                alertReached: $alertReached,
                monetary: $monetary,
            );
        }

        if ($this->shadow->requiresPositiveMonetaryBudgets() && $cost > 0) {
            if (! $monetary['configured']) {
                $blockReason = self::BLOCK_BUDGET_NOT_CONFIGURED;
            } elseif (! $monetary['allowed']) {
                $blockReason = $monetary['block_reason'] ?? self::BLOCK_MONETARY_TENANT;
            }
        } elseif ($this->shadow->requiresPositiveMonetaryBudgets() && $cost === 0 && $class->isBillable()) {
            // Preço zero em classe faturável sem estimativa: exige budget configurado mesmo assim.
            if (! $monetary['configured']) {
                $blockReason = self::BLOCK_BUDGET_NOT_CONFIGURED;
            }
        }

        if ($blockReason === null && $franchise !== null && $projected > $franchise) {
            $blockReason = self::BLOCK_FRANCHISE;
        }

        $wouldBlock = $blockReason !== null && ! $isEssential;
        $allowed = true;

        if ($this->shadow->isCommercialBlockingEnabled() && $blockReason !== null) {
            // Essenciais ainda passam em estouro de franquia, mas não em budget monetário ausente/estourado.
            $hardBlocks = [
                self::BLOCK_BUDGET_NOT_CONFIGURED,
                self::BLOCK_MONETARY_GLOBAL,
                self::BLOCK_MONETARY_TENANT,
                self::BLOCK_MONETARY_CANARY,
                self::BLOCK_MONETARY_OPERATION,
                self::BLOCK_PRICE_UNKNOWN,
            ];
            if (in_array($blockReason, $hardBlocks, true) || ! $isEssential) {
                $allowed = false;
                $wouldBlock = true;
            }
        }

        return $this->result(
            allowed: $allowed,
            wouldBlock: $blockReason !== null && (! $isEssential || ! $allowed),
            blockReason: $blockReason,
            year: $year,
            month: $month,
            cycleCode: $cycle['cycle_code'],
            used: $used,
            openReserved: $openReserved,
            franchise: $franchise,
            remaining: $remaining,
            ratio: $ratio,
            alertReached: $alertReached || ($blockReason !== null),
            monetary: $monetary,
        );
    }

    /**
     * Reserva atômica de micros nos budgets aplicáveis (dentro de transação do caller).
     *
     * @return list<int> IDs de budget tocados
     */
    public function atomicReserveMicros(
        int $tenantId,
        int $costMicros,
        string $cycleCode,
        bool $isCanary = false,
        ?string $operationKey = null,
        string $environment = 'PRODUCTION',
        Carbon|string|null $at = null,
    ): array {
        if ($costMicros <= 0) {
            return [];
        }

        $at = $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : now());
        $ids = [];

        $targets = $this->activeBudgets(
            tenantId: $tenantId,
            isCanary: $isCanary,
            operationKey: $operationKey,
            environment: $environment,
            cycleCode: $cycleCode,
            at: $at,
            forUpdate: true,
        );

        foreach ($targets as $budget) {
            $remaining = $budget->remainingMicros();
            if ($remaining < $costMicros) {
                throw new \RuntimeException('BUDGET_RESERVE_RACE: saldo insuficiente em '.$budget->scope);
            }
            $budget->reserved_micros = (int) $budget->reserved_micros + $costMicros;
            $budget->save();
            $ids[] = (int) $budget->id;
        }

        return $ids;
    }

    /**
     * Converte reserva em consumo (finalize) ou libera (release).
     */
    public function settleReservedMicros(array $budgetIds, int $costMicros, bool $consume): void
    {
        if ($costMicros <= 0 || $budgetIds === []) {
            return;
        }

        $budgets = SerproUsageBudget::query()
            ->whereIn('id', $budgetIds)
            ->lockForUpdate()
            ->get();

        foreach ($budgets as $budget) {
            $budget->reserved_micros = max(0, (int) $budget->reserved_micros - $costMicros);
            if ($consume) {
                $budget->consumed_micros = (int) $budget->consumed_micros + $costMicros;
            }
            $budget->save();
        }
    }

    public function franchiseQuota(int $tenantId): ?int
    {
        $sub = TenantSubscription::query()->where('tenant_id', $tenantId)->first();

        if ($sub === null) {
            return null;
        }

        return $sub->monthly_api_quota;
    }

    public function billableQuantityForTenant(int $tenantId, Carbon $start, Carbon $end): int
    {
        return (int) SerproApiUsageEntry::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_billable_attempt', true)
            ->where('consumption_class', '!=', SerproConsumptionClass::NaoFaturavel->value)
            ->whereBetween('occurred_at', [$start, $end])
            ->sum('quantity');
    }

    public function openReservedQuantityForTenant(int $tenantId, Carbon $start, Carbon $end): int
    {
        return (int) SerproApiUsageReservation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', SerproUsageReservationStatus::Reserved->value)
            ->where('consumption_class', '!=', SerproConsumptionClass::NaoFaturavel->value)
            ->whereBetween('reserved_at', [$start, $end])
            ->sum('quantity');
    }

    /**
     * @return array<string, mixed>
     */
    public function tenantSnapshot(int $tenantId, Carbon|string|null $at = null): array
    {
        $at = $at instanceof Carbon ? $at : ($at ? Carbon::parse($at) : now());
        $eval = $this->evaluate(
            tenantId: $tenantId,
            class: SerproConsumptionClass::Consulta,
            quantity: 0,
            isEssential: true,
            at: $at,
        );

        $cycle = $this->cycles->resolve($at);
        $cost = (int) SerproApiUsageEntry::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereBetween('occurred_at', [$cycle['period_start'], $cycle['period_end']])
            ->whereNotNull('estimated_cost_micros')
            ->sum('estimated_cost_micros');

        return [
            'tenant_id' => $tenantId,
            'period_year' => $eval['period_year'],
            'period_month' => $eval['period_month'],
            'cycle_code' => $eval['cycle_code'],
            'used_quantity' => $eval['used_quantity'],
            'reserved_open_quantity' => $eval['reserved_open_quantity'],
            'franchise_quota' => $eval['franchise_quota'],
            'remaining' => $eval['remaining'],
            'franchise_ratio' => $eval['franchise_ratio'],
            'alert_threshold_reached' => $eval['alert_threshold_reached'],
            'estimated_cost_micros' => $cost,
            'policy' => $this->shadow->snapshot(),
        ];
    }

    /**
     * @return array{
     *   configured: bool,
     *   allowed: bool,
     *   block_reason: string|null,
     *   global_remaining: int|null,
     *   tenant_remaining: int|null,
     *   canary_remaining: int|null,
     *   operation_remaining: int|null
     * }
     */
    private function evaluateMonetary(
        int $tenantId,
        int $costMicros,
        bool $isCanary,
        ?string $operationKey,
        string $environment,
        string $cycleCode,
        Carbon $at,
    ): array {
        if (! $this->shadow->requiresPositiveMonetaryBudgets()) {
            return [
                'configured' => true,
                'allowed' => true,
                'block_reason' => null,
                'global_remaining' => null,
                'tenant_remaining' => null,
                'canary_remaining' => null,
                'operation_remaining' => null,
            ];
        }

        $budgets = $this->activeBudgets(
            tenantId: $tenantId,
            isCanary: $isCanary,
            operationKey: $operationKey,
            environment: $environment,
            cycleCode: $cycleCode,
            at: $at,
            forUpdate: false,
        );

        $global = $budgets->first(fn (SerproUsageBudget $b) => $b->scope === self::SCOPE_GLOBAL && ! $b->is_canary);
        $tenant = $budgets->first(fn (SerproUsageBudget $b) => $b->scope === self::SCOPE_TENANT && ! $b->is_canary && (int) $b->tenant_id === $tenantId);
        $canary = $isCanary
            ? $budgets->first(fn (SerproUsageBudget $b) => $b->is_canary && (int) ($b->tenant_id ?? $tenantId) === $tenantId)
            : null;
        $operation = $operationKey !== null
            ? $budgets->first(fn (SerproUsageBudget $b) => $b->scope === self::SCOPE_OPERATION && $b->operation_key === $operationKey)
            : null;

        // Global + Tenant obrigatórios; canário se is_canary; operation se configurado.
        if ($global === null || ! $global->isPositive() || $tenant === null || ! $tenant->isPositive()) {
            return [
                'configured' => false,
                'allowed' => false,
                'block_reason' => self::BLOCK_BUDGET_NOT_CONFIGURED,
                'global_remaining' => $global?->remainingMicros(),
                'tenant_remaining' => $tenant?->remainingMicros(),
                'canary_remaining' => $canary?->remainingMicros(),
                'operation_remaining' => $operation?->remainingMicros(),
            ];
        }

        if ($isCanary && ($canary === null || ! $canary->isPositive())) {
            return [
                'configured' => false,
                'allowed' => false,
                'block_reason' => self::BLOCK_BUDGET_NOT_CONFIGURED,
                'global_remaining' => $global->remainingMicros(),
                'tenant_remaining' => $tenant->remainingMicros(),
                'canary_remaining' => $canary?->remainingMicros(),
                'operation_remaining' => $operation?->remainingMicros(),
            ];
        }

        if ($global->remainingMicros() < $costMicros) {
            return [
                'configured' => true,
                'allowed' => false,
                'block_reason' => self::BLOCK_MONETARY_GLOBAL,
                'global_remaining' => $global->remainingMicros(),
                'tenant_remaining' => $tenant->remainingMicros(),
                'canary_remaining' => $canary?->remainingMicros(),
                'operation_remaining' => $operation?->remainingMicros(),
            ];
        }

        if ($tenant->remainingMicros() < $costMicros) {
            return [
                'configured' => true,
                'allowed' => false,
                'block_reason' => self::BLOCK_MONETARY_TENANT,
                'global_remaining' => $global->remainingMicros(),
                'tenant_remaining' => $tenant->remainingMicros(),
                'canary_remaining' => $canary?->remainingMicros(),
                'operation_remaining' => $operation?->remainingMicros(),
            ];
        }

        if ($isCanary && $canary !== null && $canary->remainingMicros() < $costMicros) {
            return [
                'configured' => true,
                'allowed' => false,
                'block_reason' => self::BLOCK_MONETARY_CANARY,
                'global_remaining' => $global->remainingMicros(),
                'tenant_remaining' => $tenant->remainingMicros(),
                'canary_remaining' => $canary->remainingMicros(),
                'operation_remaining' => $operation?->remainingMicros(),
            ];
        }

        if ($operation !== null && $operation->isPositive() && $operation->remainingMicros() < $costMicros) {
            return [
                'configured' => true,
                'allowed' => false,
                'block_reason' => self::BLOCK_MONETARY_OPERATION,
                'global_remaining' => $global->remainingMicros(),
                'tenant_remaining' => $tenant->remainingMicros(),
                'canary_remaining' => $canary?->remainingMicros(),
                'operation_remaining' => $operation->remainingMicros(),
            ];
        }

        return [
            'configured' => true,
            'allowed' => true,
            'block_reason' => null,
            'global_remaining' => $global->remainingMicros(),
            'tenant_remaining' => $tenant->remainingMicros(),
            'canary_remaining' => $canary?->remainingMicros(),
            'operation_remaining' => $operation?->remainingMicros(),
        ];
    }

    /**
     * @return Collection<int, SerproUsageBudget>
     */
    private function activeBudgets(
        int $tenantId,
        bool $isCanary,
        ?string $operationKey,
        string $environment,
        string $cycleCode,
        Carbon $at,
        bool $forUpdate,
    ) {
        $q = SerproUsageBudget::query()
            ->where('is_active', true)
            ->where('environment', $environment)
            ->where('budget_kind', 'MONETARY')
            ->where('effective_from', '<=', $at)
            ->where(function ($w) use ($at): void {
                $w->whereNull('effective_to')->orWhere('effective_to', '>=', $at);
            })
            ->where(function ($w) use ($cycleCode): void {
                $w->whereNull('cycle_code')->orWhere('cycle_code', $cycleCode);
            })
            ->where(function ($w) use ($tenantId, $isCanary, $operationKey): void {
                $w->where(function ($g): void {
                    $g->where('scope', self::SCOPE_GLOBAL)->whereNull('tenant_id');
                })->orWhere(function ($o) use ($tenantId): void {
                    $o->where('scope', self::SCOPE_TENANT)->where('tenant_id', $tenantId);
                });
                if ($isCanary) {
                    $w->orWhere(function ($c) use ($tenantId): void {
                        $c->where('is_canary', true)
                            ->where(function ($x) use ($tenantId): void {
                                $x->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
                            });
                    });
                }
                if ($operationKey !== null) {
                    $w->orWhere(function ($op) use ($operationKey, $tenantId): void {
                        $op->where('scope', self::SCOPE_OPERATION)
                            ->where('operation_key', $operationKey)
                            ->where(function ($x) use ($tenantId): void {
                                $x->whereNull('tenant_id')->orWhere('tenant_id', $tenantId);
                            });
                    });
                }
            });

        if ($forUpdate) {
            $q->lockForUpdate();
        }

        return $q->get();
    }

    /**
     * @param  array<string, mixed>  $monetary
     * @return array<string, mixed>
     */
    private function result(
        bool $allowed,
        bool $wouldBlock,
        ?string $blockReason,
        int $year,
        int $month,
        string $cycleCode,
        int $used,
        int $openReserved,
        ?int $franchise,
        ?int $remaining,
        ?float $ratio,
        bool $alertReached,
        array $monetary,
    ): array {
        return [
            'allowed' => $allowed,
            'would_block' => $wouldBlock,
            'block_reason' => $blockReason,
            'period_year' => $year,
            'period_month' => $month,
            'cycle_code' => $cycleCode,
            'used_quantity' => $used,
            'reserved_open_quantity' => $openReserved,
            'franchise_quota' => $franchise,
            'remaining' => $remaining,
            'franchise_ratio' => $ratio,
            'alert_threshold_reached' => $alertReached,
            'monetary' => $monetary,
        ];
    }
}

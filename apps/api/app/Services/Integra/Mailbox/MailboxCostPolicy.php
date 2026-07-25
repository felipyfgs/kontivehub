<?php

namespace App\Services\Integra\Mailbox;

use App\Enums\SerproConsumptionClass;
use App\Models\MailboxMonitoringSetting;
use App\Models\SerproApiUsageEntry;
use App\Services\Serpro\Usage\PriceCalculator;

/** Preview transparente e bloqueio local anterior a consultas faturáveis. */
final class MailboxCostPolicy
{
    public function __construct(private readonly PriceCalculator $prices) {}

    /**
     * @return array{operation:string,quantity:int,estimated_cost_micros:?int,unit_cost_micros:?int,currency:?string,price_source:string,price_revision:?string,allowed:bool,block_reason:?string,budget_micros:?int,spent_micros:int}
     */
    public function preview(int $officeId, string $operation, int $quantity = 1): array
    {
        $operation = strtoupper(trim($operation));
        if (! in_array($operation, ['LISTAR', 'DETALHE'], true)) {
            throw new \InvalidArgumentException('MAILBOX_COST_OPERATION_INVALID');
        }
        $quantity = max(1, $quantity);
        $estimate = $this->prices->estimate(
            class: SerproConsumptionClass::Consulta,
            quantity: $quantity,
            systemCode: 'INTEGRA_CAIXAPOSTAL',
            serviceCode: 'CAIXA_POSTAL',
            operationCode: $operation,
            productionOnly: false,
        );
        $source = $estimate['price_version_id'] === null
            ? 'UNKNOWN'
            : ($estimate['authorizes_production'] ? 'OFFICIAL' : 'SHADOW');
        $setting = MailboxMonitoringSetting::query()->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->first();
        $budget = $setting?->monthly_budget_micros;
        $spent = (int) SerproApiUsageEntry::query()->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('is_billable_attempt', true)
            ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('estimated_cost_micros');
        $cost = $estimate['estimated_cost_micros'];
        $block = null;
        if ($cost === null || $estimate['price_unknown']) {
            $block = 'PRICE_UNKNOWN';
        } elseif ($budget !== null && ($spent + $cost) > $budget) {
            $block = 'MAILBOX_MONTHLY_BUDGET_EXCEEDED';
        }

        return [
            'operation' => $operation,
            'quantity' => $quantity,
            'estimated_cost_micros' => $cost,
            'unit_cost_micros' => $estimate['unit_cost_micros'],
            'currency' => $estimate['currency'],
            'price_source' => $source,
            'price_revision' => $estimate['price_revision'],
            'allowed' => $block === null,
            'block_reason' => $block,
            'budget_micros' => $budget,
            'spent_micros' => $spent,
        ];
    }

    public function assertAllowed(int $officeId, string $operation, int $quantity = 1): array
    {
        $preview = $this->preview($officeId, $operation, $quantity);
        if (! $preview['allowed']) {
            throw new \RuntimeException((string) $preview['block_reason']);
        }

        return $preview;
    }
}

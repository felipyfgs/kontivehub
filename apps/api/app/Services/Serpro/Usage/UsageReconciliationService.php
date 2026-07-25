<?php

namespace App\Services\Serpro\Usage;

use App\Enums\SerproReconciliationStatus;
use App\Models\SerproApiUsageEntry;
use App\Models\SerproBillingInvoiceLine;
use App\Models\SerproUsageIncident;
use App\Models\SerproUsageReconciliation;
use App\Models\SerproUsageReconciliationAdjustment;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Importa/registra fatura e detalhamento SERPRO; cria incidentes de divergência.
 * Nunca reescreve serpro_api_usage_entries. Isola totais tenant/global.
 */
final class UsageReconciliationService
{
    public function __construct(
        private readonly UsageAggregationService $aggregates,
        private readonly BillingCycleResolver $cycles,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{
     *     office_id?: int|null,
     *     service_code?: string|null,
     *     consumption_class?: string|null,
     *     amount_micros: int,
     *     reason: string,
     *     notes?: string|null
     * }>  $adjustments
     * @param  list<array{
     *     office_id?: int|null,
     *     functional_route?: string|null,
     *     http_status?: int|null,
     *     request_tag?: string|null,
     *     system_code?: string|null,
     *     service_code?: string|null,
     *     operation_code?: string|null,
     *     consumption_class?: string|null,
     *     quantity?: int,
     *     official_cost_micros: int
     * }>  $detailLines
     */
    public function registerOfficialInvoice(
        int $year,
        int $month,
        int $officialTotalCostMicros,
        ?string $officialReference = null,
        ?string $officialSource = null,
        ?string $notes = null,
        ?int $importedByUserId = null,
        array $adjustments = [],
        ?string $differenceCause = null,
        bool $recomputeAggregates = true,
        ?string $cycleCode = null,
        array $detailLines = [],
        ?int $officeIdScope = null,
    ): SerproUsageReconciliation {
        $periodKind = $cycleCode !== null
            ? UsageAggregationService::PERIOD_BILLING_CYCLE
            : UsageAggregationService::PERIOD_CALENDAR;

        if ($recomputeAggregates) {
            if ($cycleCode !== null) {
                $this->aggregates->recomputeBillingCycle();
            } else {
                $this->aggregates->recomputeMonth($year, $month);
            }
        }

        if ($cycleCode !== null) {
            $internal = $this->aggregates->internalEstimatedTotalMicrosForCycle($cycleCode);
            $cycle = $this->cycles->ensurePersisted();
            $start = Carbon::parse($cycle->period_start)->startOfDay();
            $end = Carbon::parse($cycle->period_end)->endOfDay();
        } else {
            $internal = $this->aggregates->internalEstimatedTotalMicros($year, $month);
            $start = Carbon::create($year, $month, 1)->startOfMonth();
            $end = $start->copy()->endOfMonth();
        }

        $live = $this->aggregates->liveTotals($start, $end, $officeIdScope);
        $difference = $officialTotalCostMicros - $internal;

        $status = match (true) {
            $difference === 0 && $adjustments === [] && $detailLines === [] => SerproReconciliationStatus::Matched,
            $adjustments !== [] => SerproReconciliationStatus::Adjusted,
            default => SerproReconciliationStatus::Divergent,
        };

        return DB::transaction(function () use (
            $year,
            $month,
            $officialTotalCostMicros,
            $officialReference,
            $officialSource,
            $notes,
            $importedByUserId,
            $adjustments,
            $differenceCause,
            $internal,
            $difference,
            $status,
            $cycleCode,
            $periodKind,
            $detailLines,
            $live,
            $start,
            $end,
            $officeIdScope,
        ): SerproUsageReconciliation {
            $payload = [
                'period_year' => $year,
                'period_month' => $month,
                'official_reference' => $officialReference,
                'official_source' => $officialSource ?? 'SERPRO_INVOICE',
                'official_total_cost_micros' => $officialTotalCostMicros,
                'internal_total_estimated_cost_micros' => $internal,
                'difference_micros' => $difference,
                'status' => $status,
                'difference_cause' => $differenceCause
                    ?? ($difference === 0 ? null : 'PENDING_REVIEW'),
                'notes' => $notes,
                'imported_by_user_id' => $importedByUserId,
                'imported_at' => now(),
            ];
            if (Schema::hasColumn('serpro_usage_reconciliations', 'cycle_code')) {
                $payload['cycle_code'] = $cycleCode;
                $payload['period_kind'] = $periodKind;
            }

            $recon = SerproUsageReconciliation::query()->create($payload);

            foreach ($adjustments as $adj) {
                SerproUsageReconciliationAdjustment::query()->create([
                    'reconciliation_id' => $recon->id,
                    'office_id' => $adj['office_id'] ?? null,
                    'service_code' => $adj['service_code'] ?? null,
                    'consumption_class' => $adj['consumption_class'] ?? null,
                    'amount_micros' => (int) $adj['amount_micros'],
                    'reason' => $adj['reason'],
                    'notes' => $adj['notes'] ?? null,
                    'created_at' => now(),
                ]);
            }

            if (Schema::hasTable('serpro_billing_invoice_lines')) {
                foreach ($detailLines as $line) {
                    $tag = $line['request_tag'] ?? null;
                    $internalLine = null;
                    if (is_string($tag) && $tag !== '') {
                        $entry = SerproApiUsageEntry::query()
                            ->withoutGlobalScopes()
                            ->where('request_tag', $tag)
                            ->first();
                        $internalLine = $entry?->estimated_cost_micros;
                    }

                    $official = (int) $line['official_cost_micros'];
                    SerproBillingInvoiceLine::query()->create([
                        'cycle_code' => $cycleCode ?? sprintf('CAL-%04d-%02d', $year, $month),
                        'reconciliation_id' => $recon->id,
                        'office_id' => $line['office_id'] ?? $officeIdScope,
                        'functional_route' => $line['functional_route'] ?? null,
                        'http_status' => $line['http_status'] ?? null,
                        'request_tag' => $tag,
                        'system_code' => $line['system_code'] ?? null,
                        'service_code' => $line['service_code'] ?? null,
                        'operation_code' => $line['operation_code'] ?? null,
                        'consumption_class' => $line['consumption_class'] ?? null,
                        'quantity' => (int) ($line['quantity'] ?? 1),
                        'official_cost_micros' => $official,
                        'internal_cost_micros' => $internalLine,
                        'difference_micros' => $official - (int) ($internalLine ?? 0),
                        'line_status' => $internalLine === null
                            ? 'UNMATCHED'
                            : ($official === (int) $internalLine ? 'MATCHED' : 'DIVERGENT'),
                        'metadata' => [
                            'period_start' => $start->toIso8601String(),
                            'period_end' => $end->toIso8601String(),
                        ],
                    ]);
                }
            }

            if ($status === SerproReconciliationStatus::Divergent || abs($difference) > 0) {
                $this->openDivergenceIncident(
                    cycleCode: $cycleCode,
                    expectedMicros: $internal,
                    observedMicros: $officialTotalCostMicros,
                    officeId: $officeIdScope,
                    reference: $officialReference,
                    live: $live,
                );
            }

            $this->audit->record(
                action: 'serpro.usage.reconciliation_registered',
                result: $status->value,
                subject: $recon,
                context: [
                    'period_year' => $year,
                    'period_month' => $month,
                    'cycle_code' => $cycleCode,
                    'difference_micros' => $difference,
                    'official_reference' => $officialReference,
                    'adjustments_count' => count($adjustments),
                    'detail_lines' => count($detailLines),
                    'tenant_micros' => $live['tenant_micros'],
                    'global_micros' => $live['global_micros'],
                ],
                userId: $importedByUserId,
            );

            return $recon->load('adjustments');
        });
    }

    /**
     * @param  array{tenant_micros: int, global_micros: int, tenant_quantity: int, global_quantity: int}  $live
     */
    private function openDivergenceIncident(
        ?string $cycleCode,
        int $expectedMicros,
        int $observedMicros,
        ?int $officeId,
        ?string $reference,
        array $live,
    ): void {
        if (! Schema::hasTable('serpro_usage_incidents')) {
            return;
        }

        SerproUsageIncident::query()->create([
            'kind' => SerproUsageIncident::KIND_RECONCILIATION_DIVERGENCE,
            'severity' => SerproUsageIncident::SEVERITY_OPEN,
            'environment' => null,
            'office_id' => $officeId,
            'cycle_code' => $cycleCode,
            'sanitized_summary' => sprintf(
                'Divergência fatura vs ledger (ref=%s, Δ=%d micros). Totais isolados tenant=%d global=%d.',
                $reference ?? 'n/a',
                $observedMicros - $expectedMicros,
                $live['tenant_micros'],
                $live['global_micros'],
            ),
            'expected_micros' => $expectedMicros,
            'observed_micros' => $observedMicros,
            'metadata' => [
                'tenant_quantity' => $live['tenant_quantity'],
                'global_quantity' => $live['global_quantity'],
            ],
            'opened_at' => now(),
        ]);
    }
}

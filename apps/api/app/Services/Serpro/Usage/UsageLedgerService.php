<?php

namespace App\Services\Serpro\Usage;

use App\DTO\Serpro\IntegraResponse;
use App\Enums\SerproBillabilityOutcome;
use App\Enums\SerproConsumptionClass;
use App\Enums\SerproDataSegregationClass;
use App\Enums\SerproUsageReservationStatus;
use App\Enums\SerproUsageResult;
use App\Models\Office;
use App\Models\SerproApiUsageEntry;
use App\Models\SerproApiUsageReservation;
use App\Services\Audit\AuditLogger;
use App\Services\Serpro\IntegraBillingClassifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;

/**
 * Ledger desacoplado: chamado antes/depois de qualquer HTTP SERPRO.
 *
 * Fluxo:
 * 1. reserve() — classifica, precifica, avalia orçamento sob lock, grava reserva idempotente
 * 2. finalize() — após resposta (sucesso ou falha possivelmente faturável) → entrada imutável
 * 3. release() — abortou antes do HTTP (não faturável)
 */
final class UsageLedgerService
{
    public function __construct(
        private readonly OperationCatalog $catalog,
        private readonly PriceCalculator $prices,
        private readonly UsageBudgetGate $budget,
        private readonly UsageShadowPolicy $shadow,
        private readonly AuditLogger $audit,
        private readonly IntegraBillingClassifier $billingClassifier,
        private readonly BillingCycleResolver $cycles,
    ) {}

    public function reserve(UsageReserveRequest $request): UsageReserveOutcome
    {
        $existing = SerproApiUsageReservation::query()
            ->withoutGlobalScopes()
            ->where('idempotency_key', $request->idempotencyKey)
            ->first();

        if ($existing !== null) {
            $this->assertSameOffice($existing, $request->officeId);

            return new UsageReserveOutcome(
                allowed: $existing->status !== SerproUsageReservationStatus::Blocked,
                reservation: $existing,
                replayed: true,
                budget: $this->budget->tenantSnapshot($request->officeId),
            );
        }

        $classified = $this->catalog->classify(
            $request->systemCode,
            $request->serviceCode,
            $request->operationCode,
        );

        $class = $classified['class'];
        $isEssential = $request->forceEssential ?? $classified['is_essential'];
        $catalogKnown = $classified['catalog_id'] !== null;

        // Rotas oficiais não faturáveis
        if (in_array($request->functionalRoute, ['Apoiar', 'Monitorar'], true)) {
            $class = SerproConsumptionClass::NaoFaturavel;
        }

        $preTransport = $this->billingClassifier->classifyPreTransport(
            $request->functionalRoute,
            catalogKnown: $catalogKnown || $class === SerproConsumptionClass::NaoFaturavel,
        );

        $estimate = $this->prices->estimate(
            class: $class,
            quantity: $request->quantity,
            systemCode: $request->systemCode,
            serviceCode: $request->serviceCode,
            operationCode: $request->operationCode,
        );

        // Simulação: custo zero e não consome budget
        if ($request->isSimulated) {
            $estimate['estimated_cost_micros'] = 0;
            $estimate['unit_cost_micros'] = 0;
            $estimate['price_unknown'] = false;
        }

        $correlationId = $request->correlationId
            ?? $this->audit->correlationId()
            ?: (string) Str::uuid();

        // Um request tag opaco por attempt (≠ idempotency key).
        $requestTag = $request->requestTag;
        if ($requestTag === null || $requestTag === '') {
            $requestTag = substr(hash('sha256', $request->idempotencyKey.'|'.$correlationId), 0, 32);
        }

        $shadow = $this->shadow->isShadowMode();
        $segregation = $request->isSimulated
            ? SerproDataSegregationClass::TrialSimulated->value
            : ($shadow
                ? SerproDataSegregationClass::Shadow->value
                : SerproDataSegregationClass::Production->value);

        $environment = $request->environment ?? (string) config('serpro.default_environment', 'TRIAL');
        $catalogRevision = $request->catalogRevision
            ?? ($classified['catalog_id'] !== null ? (string) $classified['catalog_id'] : null);

        /** @var array{reservation: SerproApiUsageReservation, budget: array<string, mixed>, allowed: bool, replayed: bool} $pack */
        $pack = DB::transaction(function () use (
            $request,
            $class,
            $isEssential,
            $estimate,
            $correlationId,
            $shadow,
            $segregation,
            $environment,
            $requestTag,
            $preTransport,
            $catalogRevision,
        ): array {
            $race = SerproApiUsageReservation::query()
                ->withoutGlobalScopes()
                ->where('idempotency_key', $request->idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($race !== null) {
                $this->assertSameOffice($race, $request->officeId);

                return [
                    'reservation' => $race,
                    'budget' => $this->budget->tenantSnapshot($request->officeId),
                    'allowed' => $race->status !== SerproUsageReservationStatus::Blocked,
                    'replayed' => true,
                ];
            }

            $this->lockOfficeBudget($request->officeId);

            $costMicros = (int) ($estimate['estimated_cost_micros'] ?? 0);
            $blockReason = null;
            $allowed = true;

            // Fail-closed: catálogo/rota/preço desconhecido em modo produtivo
            if (! $request->isSimulated && $this->shadow->isProductiveBillingMode()) {
                if ($preTransport['outcome'] === SerproBillabilityOutcome::UnknownBlocked) {
                    $allowed = false;
                    $blockReason = $preTransport['reason'] ?? 'BILLING_RULE_UNKNOWN';
                } elseif ($class === SerproConsumptionClass::Desconhecida && ! $this->shadow->failOpenOnUnknown()) {
                    $allowed = false;
                    $blockReason = UsageBudgetGate::BLOCK_PRICE_UNKNOWN;
                } elseif ((bool) ($estimate['price_unknown'] ?? false) && $class->isBillable()) {
                    $allowed = false;
                    $blockReason = UsageBudgetGate::BLOCK_PRICE_UNKNOWN;
                } elseif (
                    $this->shadow->requiresProductionPriceTable()
                    && $class->isBillable()
                    && ! (bool) ($estimate['authorizes_production'] ?? false)
                ) {
                    $allowed = false;
                    $blockReason = UsageBudgetGate::BLOCK_PRICE_UNKNOWN;
                }
            }

            $budgetEnvironment = strtoupper((string) $environment);

            $budgetEval = $this->budget->evaluate(
                officeId: $request->officeId,
                class: $class,
                quantity: $request->quantity,
                isEssential: $isEssential,
                estimatedCostMicros: $request->isSimulated ? 0 : $costMicros,
                isCanary: $request->isCanary,
                operationKey: $request->operationKey,
                environment: $budgetEnvironment,
            );

            if ($allowed && ! (bool) $budgetEval['allowed']) {
                $allowed = false;
                $blockReason = $budgetEval['block_reason'] ?? 'BUDGET_BLOCKED';
            }

            if ($request->isSimulated) {
                $allowed = true;
                $blockReason = null;
            }

            $budgetIds = [];
            if ($allowed && ! $request->isSimulated && $costMicros > 0 && $this->shadow->requiresPositiveMonetaryBudgets()) {
                try {
                    $budgetIds = $this->budget->atomicReserveMicros(
                        officeId: $request->officeId,
                        costMicros: $costMicros,
                        cycleCode: (string) $budgetEval['cycle_code'],
                        isCanary: $request->isCanary,
                        operationKey: $request->operationKey,
                        environment: $budgetEnvironment,
                    );
                } catch (\RuntimeException $e) {
                    if (! str_contains($e->getMessage(), 'BUDGET_RESERVE_RACE')) {
                        throw $e;
                    }
                    // Corrida entre evaluate e reserve: fail-closed com reserva Blocked.
                    $allowed = false;
                    $blockReason = UsageBudgetGate::BLOCK_MONETARY_GLOBAL;
                    $budgetEval = array_merge($budgetEval, [
                        'allowed' => false,
                        'would_block' => true,
                        'block_reason' => $blockReason,
                    ]);
                }
            }

            $status = $allowed
                ? SerproUsageReservationStatus::Reserved
                : SerproUsageReservationStatus::Blocked;

            $create = [
                'office_id' => $request->officeId,
                'idempotency_key' => $request->idempotencyKey,
                'client_id' => $request->clientId,
                'contributor_ref' => $request->contributorRef,
                'system_code' => $request->systemCode,
                'service_code' => $request->serviceCode,
                'operation_code' => $request->operationCode,
                'consumption_class' => $class,
                'quantity' => $request->quantity,
                'is_essential' => $isEssential,
                'status' => $status,
                'correlation_id' => $correlationId,
                'price_version_id' => $request->isSimulated ? null : $estimate['price_version_id'],
                'estimated_cost_micros' => $request->isSimulated ? 0 : $estimate['estimated_cost_micros'],
                'shadow_mode' => $shadow,
                'would_block' => $request->isSimulated ? false : ((bool) $budgetEval['would_block'] || ! $allowed),
                'block_reason' => $allowed ? null : $blockReason,
                'result' => $allowed ? null : SerproUsageResult::BlockedByBudget,
                'reserved_at' => now(),
                'finalized_at' => $allowed ? null : now(),
            ];

            if (Schema::hasColumn('serpro_api_usage_reservations', 'operation_key')) {
                $create['operation_key'] = $request->operationKey;
                $create['is_simulated'] = $request->isSimulated;
                $create['request_tag'] = $requestTag;
                $create['functional_route'] = $request->functionalRoute;
            }
            if (Schema::hasColumn('serpro_api_usage_reservations', 'environment')) {
                $create['environment'] = $environment;
                $create['serpro_contract_id'] = $request->serproContractId;
                $create['catalog_revision'] = $catalogRevision;
                $create['price_revision'] = $estimate['price_revision'] ?? null;
                $create['segregation_class'] = $segregation;
                $create['attempt_state'] = $allowed ? 'reserved' : 'blocked';
            }

            $reservation = SerproApiUsageReservation::query()->create($create);

            if ($budgetIds !== [] && Schema::hasColumn('serpro_api_usage_reservations', 'durable_result_ref') === false) {
                // metadata em block_reason não — guarda ids no correlation via refresh opcional
            }
            // Persist budget ids em metadata se coluna existir (json notes não há); usa durable_result_ref como ref opaca.
            if ($budgetIds !== [] && Schema::hasColumn('serpro_api_usage_reservations', 'durable_result_ref')) {
                $reservation->durable_result_ref = 'budgets:'.implode(',', $budgetIds);
                $reservation->save();
            }

            return [
                'reservation' => $reservation,
                'budget' => $budgetEval,
                'allowed' => $allowed,
                'replayed' => false,
            ];
        });

        $reservation = $pack['reservation'];
        $budgetEval = $pack['budget'];
        $allowed = $pack['allowed'];

        if ($reservation->status === SerproUsageReservationStatus::Blocked) {
            $this->audit->record(
                action: 'serpro.usage.blocked',
                result: 'BLOCKED',
                subject: $reservation,
                context: [
                    'office_id' => $request->officeId,
                    'service_code' => $request->serviceCode,
                    'operation_code' => $request->operationCode,
                    'block_reason' => $reservation->block_reason,
                ],
                officeId: $request->officeId,
            );
        }

        return new UsageReserveOutcome(
            allowed: $allowed,
            reservation: $reservation,
            replayed: (bool) $pack['replayed'],
            budget: $budgetEval,
        );
    }

    public function finalize(
        SerproApiUsageReservation $reservation,
        SerproUsageResult $result,
        ?int $latencyMs = null,
        ?int $httpStatus = null,
        ?bool $possiblyBillable = null,
    ): SerproApiUsageEntry {
        $reservation = SerproApiUsageReservation::query()
            ->withoutGlobalScopes()
            ->whereKey($reservation->id)
            ->firstOrFail();

        if ($reservation->status === SerproUsageReservationStatus::Finalized) {
            $entry = SerproApiUsageEntry::query()
                ->withoutGlobalScopes()
                ->where('reservation_id', $reservation->id)
                ->first();

            if ($entry !== null) {
                return $entry;
            }
        }

        if ($reservation->status === SerproUsageReservationStatus::Blocked) {
            throw new LogicException('Reserva bloqueada não pode ser finalizada como chamada SERPRO.');
        }

        if ($reservation->status === SerproUsageReservationStatus::Released) {
            throw new LogicException('Reserva liberada não pode ser finalizada.');
        }

        $billable = $possiblyBillable ?? $result->possiblyBillableByDefault();
        $outcome = null;

        if ($httpStatus !== null) {
            $route = $reservation->functional_route ?? null;
            $outcome = $this->billingClassifier->classifyPostTransport(
                is_string($route) ? $route : null,
                $httpStatus,
            );
            $billable = $outcome->isBillableAttempt() && $billable;
        } elseif (in_array($result, [SerproUsageResult::Timeout, SerproUsageResult::TransportError, SerproUsageResult::Unknown], true)) {
            // Timeout/transporte incerto → POSSIBLY_BILLABLE
            $outcome = SerproBillabilityOutcome::PossiblyBillable;
            $billable = true;
            $possiblyBillable = true;
        }

        if ($reservation->consumption_class->value === 'NAO_FATURAVEL') {
            $billable = false;
        }

        if ((bool) ($reservation->is_simulated ?? false)) {
            $billable = false;
        }

        $remoteState = match (true) {
            $outcome === SerproBillabilityOutcome::PossiblyBillable,
            $result === SerproUsageResult::Timeout,
            $result === SerproUsageResult::TransportError => 'uncertain',
            default => 'acknowledged',
        };

        return DB::transaction(function () use (
            $reservation,
            $result,
            $latencyMs,
            $httpStatus,
            $billable,
            $remoteState,
            $possiblyBillable,
        ): SerproApiUsageEntry {
            $locked = SerproApiUsageReservation::query()
                ->withoutGlobalScopes()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === SerproUsageReservationStatus::Finalized) {
                return SerproApiUsageEntry::query()
                    ->withoutGlobalScopes()
                    ->where('reservation_id', $locked->id)
                    ->firstOrFail();
            }

            if ($locked->status === SerproUsageReservationStatus::Released) {
                throw new LogicException('Reserva liberada não pode ser finalizada.');
            }

            if ($locked->status === SerproUsageReservationStatus::Blocked) {
                throw new LogicException('Reserva bloqueada não pode ser finalizada como chamada SERPRO.');
            }

            $existingEntry = SerproApiUsageEntry::query()
                ->withoutGlobalScopes()
                ->where('idempotency_key', $locked->idempotency_key)
                ->first();

            if ($existingEntry !== null) {
                $locked->status = SerproUsageReservationStatus::Finalized;
                $locked->result = $result;
                $locked->latency_ms = $latencyMs;
                $locked->http_status = $httpStatus;
                $locked->possibly_billable = $billable;
                $locked->finalized_at = now();
                if (Schema::hasColumn('serpro_api_usage_reservations', 'attempt_state')) {
                    $locked->attempt_state = $remoteState === 'uncertain' ? 'uncertain' : 'acknowledged';
                    $locked->remote_state = $remoteState;
                }
                $locked->save();

                return $existingEntry;
            }

            $cost = (int) ($locked->estimated_cost_micros ?? 0);
            $this->settleBudgetsFromReservation($locked, $cost, consume: $billable);

            $entryData = [
                'office_id' => $locked->office_id,
                'reservation_id' => $locked->id,
                'idempotency_key' => $locked->idempotency_key,
                'client_id' => $locked->client_id,
                'contributor_ref' => $locked->contributor_ref,
                'system_code' => $locked->system_code,
                'service_code' => $locked->service_code,
                'operation_code' => $locked->operation_code,
                'consumption_class' => $locked->consumption_class,
                'quantity' => $locked->quantity,
                'result' => $result,
                'correlation_id' => $locked->correlation_id,
                'price_version_id' => $locked->price_version_id,
                'estimated_cost_micros' => (bool) ($locked->is_simulated ?? false) ? 0 : $locked->estimated_cost_micros,
                'is_billable_attempt' => $billable,
                'latency_ms' => $latencyMs,
                'http_status' => $httpStatus,
                'shadow_mode' => $locked->shadow_mode,
                'occurred_at' => now(),
                'created_at' => now(),
            ];
            if (Schema::hasColumn('serpro_api_usage_entries', 'operation_key')) {
                $entryData['operation_key'] = $locked->operation_key;
                $entryData['is_simulated'] = (bool) $locked->is_simulated;
                $entryData['request_tag'] = $locked->request_tag;
                $entryData['functional_route'] = $locked->functional_route;
            }
            if (Schema::hasColumn('serpro_api_usage_entries', 'environment')) {
                $entryData['environment'] = $locked->environment ?? null;
                $entryData['serpro_contract_id'] = $locked->serpro_contract_id ?? null;
                $entryData['attempt_state'] = $remoteState === 'uncertain' ? 'uncertain' : 'acknowledged';
                $entryData['catalog_revision'] = $locked->catalog_revision ?? null;
                $entryData['price_revision'] = $locked->price_revision ?? null;
                $entryData['remote_state'] = $remoteState;
                $entryData['segregation_class'] = $locked->segregation_class
                    ?? ($locked->shadow_mode ? SerproDataSegregationClass::Shadow->value : SerproDataSegregationClass::Production->value);
            }

            $entry = SerproApiUsageEntry::query()->create($entryData);

            $locked->status = SerproUsageReservationStatus::Finalized;
            $locked->result = $result;
            $locked->latency_ms = $latencyMs;
            $locked->http_status = $httpStatus;
            $locked->possibly_billable = $possiblyBillable ?? $billable;
            $locked->finalized_at = now();
            if (Schema::hasColumn('serpro_api_usage_reservations', 'attempt_state')) {
                $locked->attempt_state = $remoteState === 'uncertain' ? 'uncertain' : 'acknowledged';
                $locked->remote_state = $remoteState;
            }
            $locked->save();

            return $entry;
        });
    }

    public function release(SerproApiUsageReservation $reservation): SerproApiUsageReservation
    {
        return DB::transaction(function () use ($reservation): SerproApiUsageReservation {
            $locked = SerproApiUsageReservation::query()
                ->withoutGlobalScopes()
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status->isTerminal()) {
                return $locked;
            }

            $cost = (int) ($locked->estimated_cost_micros ?? 0);
            $this->settleBudgetsFromReservation($locked, $cost, consume: false);

            $locked->status = SerproUsageReservationStatus::Released;
            $locked->result = SerproUsageResult::Released;
            $locked->possibly_billable = false;
            $locked->finalized_at = now();
            if (Schema::hasColumn('serpro_api_usage_reservations', 'attempt_state')) {
                $locked->attempt_state = 'released';
            }
            $locked->save();

            return $locked;
        });
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return array{outcome: UsageReserveOutcome, result: T|null, entry: SerproApiUsageEntry|null, error: \Throwable|null}
     */
    public function around(UsageReserveRequest $request, callable $call): array
    {
        $outcome = $this->reserve($request);

        if (! $outcome->allowed) {
            return [
                'outcome' => $outcome,
                'result' => null,
                'entry' => null,
                'error' => null,
            ];
        }

        $started = hrtime(true);
        try {
            $result = $call();
            $latency = (int) ((hrtime(true) - $started) / 1_000_000);
            [$usageResult, $httpStatus, $latencyOverride] = $this->resolveUsageResultFromCallback($result);
            if ($latencyOverride !== null) {
                $latency = $latencyOverride;
            }

            $entry = $this->finalize(
                $outcome->reservation,
                $usageResult,
                latencyMs: $latency,
                httpStatus: $httpStatus,
            );

            return [
                'outcome' => $outcome,
                'result' => $result,
                'entry' => $entry,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $latency = (int) ((hrtime(true) - $started) / 1_000_000);
            $entry = $this->finalize(
                $outcome->reservation,
                SerproUsageResult::TransportError,
                latencyMs: $latency,
                possiblyBillable: true,
            );

            return [
                'outcome' => $outcome,
                'result' => null,
                'entry' => $entry,
                'error' => $e,
            ];
        }
    }

    public function mapIntegraResponse(IntegraResponse $response): SerproUsageResult
    {
        if ($response->success) {
            return SerproUsageResult::Success;
        }

        if ($response->httpStatus === 0 || $response->errorCode === 'TRANSPORT_ERROR') {
            return SerproUsageResult::TransportError;
        }

        if ($response->httpStatus === 408 || $response->httpStatus === 504
            || $response->errorCode === 'TIMEOUT') {
            return SerproUsageResult::Timeout;
        }

        if ($response->httpStatus >= 500) {
            return SerproUsageResult::HttpError;
        }

        if ($response->httpStatus >= 400) {
            return SerproUsageResult::ClientError;
        }

        return SerproUsageResult::Unknown;
    }

    private function settleBudgetsFromReservation(SerproApiUsageReservation $locked, int $cost, bool $consume): void
    {
        if ($cost <= 0 || ! $this->shadow->requiresPositiveMonetaryBudgets()) {
            return;
        }

        $ref = (string) ($locked->durable_result_ref ?? '');
        if (! str_starts_with($ref, 'budgets:')) {
            return;
        }
        $ids = array_values(array_filter(array_map(
            'intval',
            explode(',', substr($ref, strlen('budgets:'))),
        )));
        if ($ids === []) {
            return;
        }
        $this->budget->settleReservedMicros($ids, $cost, $consume);
    }

    private function assertSameOffice(SerproApiUsageReservation $reservation, int $officeId): void
    {
        if ((int) $reservation->office_id !== $officeId) {
            throw new LogicException(
                'idempotency_key já utilizado por outro office_id (isolamento de tenant).'
            );
        }
    }

    private function lockOfficeBudget(int $officeId): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(?, ?)', [0x5E12_0001, $officeId]);

            return;
        }

        Office::query()
            ->whereKey($officeId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return array{0: SerproUsageResult, 1: int|null, 2: int|null}
     */
    private function resolveUsageResultFromCallback(mixed $result): array
    {
        if ($result instanceof IntegraResponse) {
            return [
                $this->mapIntegraResponse($result),
                $result->httpStatus > 0 ? $result->httpStatus : null,
                $result->latencyMs,
            ];
        }

        if (is_array($result)) {
            $usage = $result['usage_result'] ?? null;
            if ($usage instanceof SerproUsageResult) {
                $http = isset($result['http_status']) ? (int) $result['http_status'] : null;
                $lat = isset($result['latency_ms']) ? (int) $result['latency_ms'] : null;

                return [$usage, $http, $lat];
            }
        }

        return [SerproUsageResult::Success, null, null];
    }
}

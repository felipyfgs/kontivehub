<?php

namespace App\Services\Usage;

use App\Enums\MonitorCommercialDispatchState;
use App\Enums\MonitorCommercialOrigin;
use App\Models\MonitorCommercialLedgerEntry;
use App\Models\OfficeSubscription;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Ledger comercial de monitores SERPRO — separado e correlacionado ao ledger técnico.
 *
 * Regras:
 * - Unidade = primeiro despacho remoto real de client+monitor no período
 * - inaugural: única por client+monitor, quota_units=0, sem recriação em renovação
 * - manual e scheduled compartilham o mesmo saldo
 * - débito somente no primeiro despacho remoto; retry/polling/pré-transporte não consomem
 * - sem crédito, rollover ou override de consultas
 */
final class MonitorCommercialLedgerService
{
    public const BLOCK_QUOTA = 'COMMERCIAL_QUOTA_EXHAUSTED';

    public const BLOCK_INTERVAL = 'COMMERCIAL_MIN_INTERVAL';

    public const BLOCK_NOT_COMMERCIAL = 'NOT_COMMERCIAL_MONITOR';

    public function __construct(
        private readonly SubscriptionPeriodService $periods,
        private readonly CommercialEntitlementService $entitlements,
    ) {}

    /**
     * @return array{
     *   monitor_key: string,
     *   period_key: string,
     *   entitlement: int,
     *   used: int,
     *   remaining: int,
     *   inaugural_available: bool,
     *   inaugural_used: bool
     * }
     */
    public function balance(
        int $officeId,
        int $clientId,
        string $monitorKey,
        ?OfficeSubscription $subscription = null,
        CarbonImmutable|string|null $at = null,
    ): array {
        $monitorKey = strtolower(trim($monitorKey));
        $subscription ??= OfficeSubscription::query()->where('office_id', $officeId)->first();
        if ($subscription === null) {
            throw new RuntimeException('Assinatura ausente para saldo comercial de monitor.');
        }

        $period = $this->periods->resolve($subscription, $at);
        $entitlement = $this->entitlements->monitorUnits($subscription);
        $used = $this->usedQuotaUnits($officeId, $clientId, $monitorKey, $period['period_key']);
        $inauguralUsed = $this->hasInaugural($officeId, $clientId, $monitorKey);

        return [
            'monitor_key' => $monitorKey,
            'period_key' => $period['period_key'],
            'entitlement' => $entitlement,
            'used' => $used,
            'remaining' => max(0, $entitlement - $used),
            'inaugural_available' => ! $inauguralUsed,
            'inaugural_used' => $inauguralUsed,
        ];
    }

    public function hasInaugural(int $officeId, int $clientId, string $monitorKey): bool
    {
        return MonitorCommercialLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('client_id', $clientId)
            ->where('monitor_key', strtolower(trim($monitorKey)))
            ->where('origin', MonitorCommercialOrigin::Inaugural)
            ->exists();
    }

    public function usedQuotaUnits(int $officeId, int $clientId, string $monitorKey, string $periodKey): int
    {
        return (int) MonitorCommercialLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('client_id', $clientId)
            ->where('monitor_key', strtolower(trim($monitorKey)))
            ->where('period_key', $periodKey)
            ->where('quota_units', '>', 0)
            ->whereIn('dispatch_state', [
                MonitorCommercialDispatchState::Dispatched->value,
                MonitorCommercialDispatchState::Completed->value,
            ])
            ->sum('quota_units');
    }

    /**
     * Garante item automático único por client+monitor+período (idempotente).
     *
     * - Se ainda não houve inaugural: materializa origin=inaugural (gratuita) como 1ª execução auto.
     * - Se inaugural pendente: reutiliza (mesmo período ou spillover).
     * - Se inaugural já despachada (manual ou auto): cria origin=scheduled no período corrente.
     * Manual no mesmo período NÃO impede o item scheduled (compartilham saldo, mas são itens distintos).
     */
    public function ensureScheduledItem(
        int $officeId,
        int $clientId,
        string $monitorKey,
        OfficeSubscription $subscription,
        CarbonImmutable|string|null $at = null,
    ): MonitorCommercialLedgerEntry {
        $monitorKey = strtolower(trim($monitorKey));
        $period = $this->periods->resolve($subscription, $at);

        // 1) Scheduled do período (idempotente).
        $existingScheduled = MonitorCommercialLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('client_id', $clientId)
            ->where('monitor_key', $monitorKey)
            ->where('period_key', $period['period_key'])
            ->where('origin', MonitorCommercialOrigin::Scheduled)
            ->first();

        if ($existingScheduled !== null) {
            return $existingScheduled;
        }

        // 2) Inaugural ainda pendente serve como execução automática única.
        $existingInaugural = MonitorCommercialLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('client_id', $clientId)
            ->where('monitor_key', $monitorKey)
            ->where('origin', MonitorCommercialOrigin::Inaugural)
            ->first();

        if ($existingInaugural !== null
            && $existingInaugural->dispatch_state === MonitorCommercialDispatchState::Pending) {
            return $existingInaugural;
        }

        // 3) Sem inaugural despachada → criar inaugural como 1º auto; senão scheduled do período.
        $useInaugural = $existingInaugural === null;
        $origin = $useInaugural
            ? MonitorCommercialOrigin::Inaugural
            : MonitorCommercialOrigin::Scheduled;
        $idempotency = $useInaugural
            ? $this->inauguralIdempotencyKey($officeId, $clientId, $monitorKey)
            : $this->scheduledIdempotencyKey(
                $officeId,
                $clientId,
                $monitorKey,
                $period['period_key'],
            );

        $existing = MonitorCommercialLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('idempotency_key', $idempotency)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return MonitorCommercialLedgerEntry::query()->create([
                'office_id' => $officeId,
                'client_id' => $clientId,
                'monitor_key' => $monitorKey,
                'origin' => $origin,
                'dispatch_state' => MonitorCommercialDispatchState::Pending,
                'quota_units' => 0,
                'period_starts_at' => $period['starts_at'],
                'period_ends_at' => $period['ends_at'],
                'period_key' => $period['period_key'],
                'idempotency_key' => $idempotency,
            ]);
        } catch (UniqueConstraintViolationException) {
            return MonitorCommercialLedgerEntry::query()
                ->withoutGlobalScopes()
                ->where('idempotency_key', $idempotency)
                ->firstOrFail();
        } catch (Throwable $e) {
            $again = MonitorCommercialLedgerEntry::query()
                ->withoutGlobalScopes()
                ->where('office_id', $officeId)
                ->where('client_id', $clientId)
                ->where('monitor_key', $monitorKey)
                ->where('period_key', $period['period_key'])
                ->where('origin', $origin)
                ->first();
            if ($again !== null) {
                return $again;
            }

            throw $e;
        }
    }

    /**
     * Autoriza e debita (se aplicável) imediatamente antes do primeiro despacho remoto.
     * Retry/polling com o mesmo correlation id reutiliza a entrada sem novo consumo.
     *
     * @return array{
     *   allowed: bool,
     *   entry: MonitorCommercialLedgerEntry|null,
     *   block_reason: string|null,
     *   inaugural: bool,
     *   debited: bool,
     *   balance: array<string, mixed>|null
     * }
     */
    public function authorizeAndDebitBeforeRemoteDispatch(
        int $officeId,
        int $clientId,
        string $monitorKey,
        MonitorCommercialOrigin $origin,
        string $idempotencyKey,
        ?string $technicalCorrelationId = null,
        ?OfficeSubscription $subscription = null,
        CarbonImmutable|string|null $at = null,
        ?int $existingEntryId = null,
    ): array {
        $monitorKey = strtolower(trim($monitorKey));

        if (! CommercialMonitorCatalog::isCommercialMonitor($monitorKey)) {
            return [
                'allowed' => true,
                'entry' => null,
                'block_reason' => null,
                'inaugural' => false,
                'debited' => false,
                'balance' => null,
            ];
        }

        $subscription ??= OfficeSubscription::query()->where('office_id', $officeId)->first();
        if ($subscription === null) {
            return [
                'allowed' => false,
                'entry' => null,
                'block_reason' => 'SUBSCRIPTION_MISSING',
                'inaugural' => false,
                'debited' => false,
                'balance' => null,
            ];
        }

        return DB::transaction(function () use (
            $officeId,
            $clientId,
            $monitorKey,
            $origin,
            $idempotencyKey,
            $technicalCorrelationId,
            $subscription,
            $at,
            $existingEntryId,
        ) {
            // Lock por office+client+monitor para concorrência de débito.
            $lockRows = MonitorCommercialLedgerEntry::query()
                ->withoutGlobalScopes()
                ->where('office_id', $officeId)
                ->where('client_id', $clientId)
                ->where('monitor_key', $monitorKey)
                ->lockForUpdate()
                ->get();

            // Replay por correlation (polling/retry): não reconsome.
            if ($technicalCorrelationId !== null && $technicalCorrelationId !== '') {
                $byCorr = $lockRows->first(
                    fn (MonitorCommercialLedgerEntry $e) => $e->technical_correlation_id === $technicalCorrelationId
                        && $e->dispatch_state->consumesQuota()
                );
                if ($byCorr !== null) {
                    return [
                        'allowed' => true,
                        'entry' => $byCorr,
                        'block_reason' => null,
                        'inaugural' => $byCorr->origin === MonitorCommercialOrigin::Inaugural,
                        'debited' => false,
                        'balance' => $this->balance($officeId, $clientId, $monitorKey, $subscription, $at),
                    ];
                }
            }

            if ($existingEntryId !== null) {
                $existing = $lockRows->firstWhere('id', $existingEntryId)
                    ?? MonitorCommercialLedgerEntry::query()
                        ->withoutGlobalScopes()
                        ->whereKey($existingEntryId)
                        ->lockForUpdate()
                        ->first();
                if ($existing !== null) {
                    return $this->debitExistingEntry($existing, $technicalCorrelationId, $subscription, $at);
                }
            }

            $byIdem = MonitorCommercialLedgerEntry::query()
                ->withoutGlobalScopes()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($byIdem !== null) {
                return $this->debitExistingEntry($byIdem, $technicalCorrelationId, $subscription, $at);
            }

            $period = $this->periods->resolve($subscription, $at);

            // Inaugural gratuita única: qualquer origem no primeiro uso (manual ou auto).
            // Scheduled com inaugural já existente nunca recria gratuidade.
            $useInaugural = $origin === MonitorCommercialOrigin::Inaugural
                || (
                    $origin !== MonitorCommercialOrigin::Scheduled
                    && ! $this->hasInaugural($officeId, $clientId, $monitorKey)
                );

            if ($useInaugural) {
                $origin = MonitorCommercialOrigin::Inaugural;
                $idempotencyKey = $this->inauguralIdempotencyKey($officeId, $clientId, $monitorKey);
            }

            $balance = $this->balance($officeId, $clientId, $monitorKey, $subscription, $at);

            if (! $useInaugural && $balance['remaining'] <= 0) {
                $blocked = $this->createOrGetPending(
                    officeId: $officeId,
                    clientId: $clientId,
                    monitorKey: $monitorKey,
                    origin: $origin,
                    period: $period,
                    idempotencyKey: $idempotencyKey,
                    state: MonitorCommercialDispatchState::BlockedQuota,
                    blockedReason: self::BLOCK_QUOTA,
                    technicalCorrelationId: $technicalCorrelationId,
                );

                return [
                    'allowed' => false,
                    'entry' => $blocked,
                    'block_reason' => self::BLOCK_QUOTA,
                    'inaugural' => false,
                    'debited' => false,
                    'balance' => $balance,
                ];
            }

            $quotaUnits = $useInaugural ? 0 : 1;
            $wasNew = ! MonitorCommercialLedgerEntry::query()
                ->withoutGlobalScopes()
                ->where('idempotency_key', $idempotencyKey)
                ->exists();

            $entry = $this->createOrGetPending(
                officeId: $officeId,
                clientId: $clientId,
                monitorKey: $monitorKey,
                origin: $origin,
                period: $period,
                idempotencyKey: $idempotencyKey,
                state: MonitorCommercialDispatchState::Dispatched,
                blockedReason: null,
                technicalCorrelationId: $technicalCorrelationId,
                quotaUnits: $quotaUnits,
                dispatchedAt: CarbonImmutable::now(),
            );

            // Race: createOrGetPending devolveu entrada já despachada — não redebita.
            $debited = $wasNew
                && $entry->dispatch_state === MonitorCommercialDispatchState::Dispatched
                && (int) $entry->quota_units === $quotaUnits;

            return [
                'allowed' => true,
                'entry' => $entry->fresh(),
                'block_reason' => null,
                'inaugural' => $entry->origin === MonitorCommercialOrigin::Inaugural,
                'debited' => $debited,
                'balance' => $this->balance($officeId, $clientId, $monitorKey, $subscription, $at),
            ];
        });
    }

    /**
     * Marca item scheduled como bloqueado por franquia (sem chamar SERPRO).
     */
    public function markBlockedQuota(MonitorCommercialLedgerEntry $entry, string $reason = self::BLOCK_QUOTA): MonitorCommercialLedgerEntry
    {
        if ($entry->dispatch_state->consumesQuota()) {
            return $entry;
        }

        $entry->forceFill([
            'dispatch_state' => MonitorCommercialDispatchState::BlockedQuota,
            'quota_units' => 0,
            'blocked_reason' => $reason,
        ])->save();

        return $entry->refresh();
    }

    public function markCompleted(MonitorCommercialLedgerEntry $entry): MonitorCommercialLedgerEntry
    {
        if ($entry->dispatch_state === MonitorCommercialDispatchState::Completed) {
            return $entry;
        }

        $entry->forceFill([
            'dispatch_state' => MonitorCommercialDispatchState::Completed,
            'completed_at' => CarbonImmutable::now(),
            // Mantém quota_units já debitada no despacho.
        ])->save();

        return $entry->refresh();
    }

    public function markFailedPreTransport(MonitorCommercialLedgerEntry $entry, string $reason): MonitorCommercialLedgerEntry
    {
        // Pré-transporte: se ainda pending, cancela sem consumo. Se já despachado, mantém débito.
        if ($entry->dispatch_state === MonitorCommercialDispatchState::Pending
            || $entry->dispatch_state === MonitorCommercialDispatchState::BlockedProxy) {
            $entry->forceFill([
                'dispatch_state' => MonitorCommercialDispatchState::Failed,
                'quota_units' => 0,
                'blocked_reason' => $reason,
            ])->save();
        }

        return $entry->refresh();
    }

    public function correlateTechnical(
        MonitorCommercialLedgerEntry $entry,
        ?string $correlationId,
        ?int $technicalUsageEntryId = null,
    ): MonitorCommercialLedgerEntry {
        $fill = [];
        if ($correlationId !== null && $correlationId !== '' && $entry->technical_correlation_id === null) {
            $fill['technical_correlation_id'] = $correlationId;
        }
        if ($technicalUsageEntryId !== null && $entry->technical_usage_entry_id === null) {
            $fill['technical_usage_entry_id'] = $technicalUsageEntryId;
        }
        if ($fill !== []) {
            $entry->forceFill($fill)->save();
        }

        return $entry->refresh();
    }

    /**
     * Último snapshot comercial + recência (para confirmação manual informada).
     *
     * @return array{
     *   last_dispatched_at: string|null,
     *   is_recent: bool,
     *   min_interval_seconds: int,
     *   seconds_since_last: int|null,
     *   can_dispatch_without_interval_block: bool
     * }
     */
    public function recentSnapshotStatus(
        int $officeId,
        int $clientId,
        string $monitorKey,
        CarbonImmutable|string|null $at = null,
    ): array {
        $monitorKey = strtolower(trim($monitorKey));
        $at = $at instanceof CarbonImmutable ? $at : ($at ? CarbonImmutable::parse($at) : CarbonImmutable::now());
        $min = CommercialMonitorCatalog::minIntervalSeconds($monitorKey);

        $last = MonitorCommercialLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('client_id', $clientId)
            ->where('monitor_key', $monitorKey)
            ->whereIn('dispatch_state', [
                MonitorCommercialDispatchState::Dispatched->value,
                MonitorCommercialDispatchState::Completed->value,
            ])
            ->orderByDesc('dispatched_at')
            ->orderByDesc('id')
            ->first();

        if ($last === null || $last->dispatched_at === null) {
            return [
                'last_dispatched_at' => null,
                'is_recent' => false,
                'min_interval_seconds' => $min,
                'seconds_since_last' => null,
                'can_dispatch_without_interval_block' => true,
            ];
        }

        $dispatchedAt = CarbonImmutable::parse($last->dispatched_at);
        $seconds = (int) max(0, $dispatchedAt->diffInSeconds($at));
        $isRecent = $seconds < $min;

        return [
            'last_dispatched_at' => $dispatchedAt->toIso8601String(),
            'is_recent' => $isRecent,
            'min_interval_seconds' => $min,
            'seconds_since_last' => $seconds,
            'can_dispatch_without_interval_block' => ! $isRecent,
        ];
    }

    public function assertMinIntervalOrBlock(
        int $officeId,
        int $clientId,
        string $monitorKey,
        CarbonImmutable|string|null $at = null,
    ): ?string {
        $status = $this->recentSnapshotStatus($officeId, $clientId, $monitorKey, $at);

        return $status['can_dispatch_without_interval_block'] ? null : self::BLOCK_INTERVAL;
    }

    public function scheduledIdempotencyKey(
        int $officeId,
        int $clientId,
        string $monitorKey,
        string $periodKey,
    ): string {
        return sprintf(
            'mcle:sched:%d:%d:%s:%s',
            $officeId,
            $clientId,
            strtolower(trim($monitorKey)),
            $periodKey,
        );
    }

    public function manualIdempotencyKey(
        int $officeId,
        int $clientId,
        string $monitorKey,
        string $periodKey,
        string $slot,
    ): string {
        return sprintf(
            'mcle:manual:%d:%d:%s:%s:%s',
            $officeId,
            $clientId,
            strtolower(trim($monitorKey)),
            $periodKey,
            $slot,
        );
    }

    public function inauguralIdempotencyKey(int $officeId, int $clientId, string $monitorKey): string
    {
        return sprintf(
            'mcle:inaugural:%d:%d:%s',
            $officeId,
            $clientId,
            strtolower(trim($monitorKey)),
        );
    }

    /**
     * @param  array{period_key: string, starts_at: CarbonImmutable, ends_at: CarbonImmutable}  $period
     */
    private function createOrGetPending(
        int $officeId,
        int $clientId,
        string $monitorKey,
        MonitorCommercialOrigin $origin,
        array $period,
        string $idempotencyKey,
        MonitorCommercialDispatchState $state,
        ?string $blockedReason,
        ?string $technicalCorrelationId,
        int $quotaUnits = 0,
        ?CarbonImmutable $dispatchedAt = null,
    ): MonitorCommercialLedgerEntry {
        $existing = MonitorCommercialLedgerEntry::query()
            ->withoutGlobalScopes()
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        if ($origin === MonitorCommercialOrigin::Inaugural) {
            $idempotencyKey = $this->inauguralIdempotencyKey($officeId, $clientId, $monitorKey);
            $existingInaugural = MonitorCommercialLedgerEntry::query()
                ->withoutGlobalScopes()
                ->where('office_id', $officeId)
                ->where('client_id', $clientId)
                ->where('monitor_key', $monitorKey)
                ->where('origin', MonitorCommercialOrigin::Inaugural)
                ->first();
            if ($existingInaugural !== null) {
                return $existingInaugural;
            }
        }

        try {
            return MonitorCommercialLedgerEntry::query()->create([
                'office_id' => $officeId,
                'client_id' => $clientId,
                'monitor_key' => $monitorKey,
                'origin' => $origin,
                'dispatch_state' => $state,
                'quota_units' => $quotaUnits,
                'period_starts_at' => $period['starts_at'],
                'period_ends_at' => $period['ends_at'],
                'period_key' => $period['period_key'],
                'idempotency_key' => $idempotencyKey,
                'technical_correlation_id' => $technicalCorrelationId,
                'dispatched_at' => $dispatchedAt,
                'blocked_reason' => $blockedReason,
            ]);
        } catch (UniqueConstraintViolationException) {
            return MonitorCommercialLedgerEntry::query()
                ->withoutGlobalScopes()
                ->where('idempotency_key', $idempotencyKey)
                ->firstOrFail();
        }
    }

    /**
     * @return array{
     *   allowed: bool,
     *   entry: MonitorCommercialLedgerEntry,
     *   block_reason: string|null,
     *   inaugural: bool,
     *   debited: bool,
     *   balance: array<string, mixed>
     * }
     */
    private function debitExistingEntry(
        MonitorCommercialLedgerEntry $entry,
        ?string $technicalCorrelationId,
        OfficeSubscription $subscription,
        CarbonImmutable|string|null $at,
    ): array {
        if ($entry->dispatch_state->consumesQuota()) {
            if ($technicalCorrelationId !== null) {
                $this->correlateTechnical($entry, $technicalCorrelationId);
            }

            return [
                'allowed' => true,
                'entry' => $entry->fresh(),
                'block_reason' => null,
                'inaugural' => $entry->origin === MonitorCommercialOrigin::Inaugural,
                'debited' => false,
                'balance' => $this->balance(
                    (int) $entry->office_id,
                    (int) $entry->client_id,
                    $entry->monitor_key,
                    $subscription,
                    $at,
                ),
            ];
        }

        if ($entry->dispatch_state === MonitorCommercialDispatchState::BlockedQuota) {
            return [
                'allowed' => false,
                'entry' => $entry,
                'block_reason' => $entry->blocked_reason ?? self::BLOCK_QUOTA,
                'inaugural' => false,
                'debited' => false,
                'balance' => $this->balance(
                    (int) $entry->office_id,
                    (int) $entry->client_id,
                    $entry->monitor_key,
                    $subscription,
                    $at,
                ),
            ];
        }

        // Pending → debit now if balance allows (scheduled/manual/inaugural).
        $isInaugural = $entry->origin === MonitorCommercialOrigin::Inaugural;
        $balance = $this->balance(
            (int) $entry->office_id,
            (int) $entry->client_id,
            $entry->monitor_key,
            $subscription,
            $at,
        );

        if (! $isInaugural && $balance['remaining'] <= 0) {
            $this->markBlockedQuota($entry);

            return [
                'allowed' => false,
                'entry' => $entry->fresh(),
                'block_reason' => self::BLOCK_QUOTA,
                'inaugural' => false,
                'debited' => false,
                'balance' => $balance,
            ];
        }

        $quota = $isInaugural ? 0 : 1;
        $entry->forceFill([
            'dispatch_state' => MonitorCommercialDispatchState::Dispatched,
            'quota_units' => $quota,
            'dispatched_at' => CarbonImmutable::now(),
            'technical_correlation_id' => $technicalCorrelationId ?? $entry->technical_correlation_id,
            'blocked_reason' => null,
        ])->save();

        return [
            'allowed' => true,
            'entry' => $entry->fresh(),
            'block_reason' => null,
            'inaugural' => $isInaugural,
            'debited' => true,
            'balance' => $this->balance(
                (int) $entry->office_id,
                (int) $entry->client_id,
                $entry->monitor_key,
                $subscription,
                $at,
            ),
        ];
    }
}

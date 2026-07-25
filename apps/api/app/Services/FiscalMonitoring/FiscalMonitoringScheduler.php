<?php

namespace App\Services\FiscalMonitoring;

use App\Enums\DctfwebCategory;
use App\Enums\FiscalControlModule;
use App\Enums\FiscalOperationClass;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\FiscalVerificationState;
use App\Enums\MonitorCommercialDispatchState;
use App\Enums\MonitorCommercialOrigin;
use App\Enums\SerproCapabilityDriver;
use App\Enums\TaxRegimeCode;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalMonitoringSchedule;
use App\Models\MonitorCommercialLedgerEntry;
use App\Models\Office;
use App\Models\OfficeMonitorSchedulePolicy;
use App\Models\OfficeSubscription;
use App\Services\Esocial\EsocialBxReadinessService;
use App\Services\Fiscal\Availability\FiscalModuleAvailabilityService;
use App\Services\Fiscal\Dctfweb\DctfwebPeriod;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdPeriod;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiYear;
use App\Services\Integra\Parcelamento\ParcelamentoServiceCatalog;
use App\Services\Serpro\CapabilityDriverResolver;
use App\Services\Usage\CommercialMonitorCatalog;
use App\Services\Usage\MonitorCommercialLedgerService;
use App\Services\Usage\SubscriptionPeriodService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Espalhamento determinístico, fila justa, limites global/tenant.
 * Revalidação completa ocorre no job imediatamente antes da chamada.
 *
 * Evolução comercial (flag): política mensal office+monitor (dia 1–28),
 * um item por cliente+monitor+período, spillover nos dias seguintes via Horizon.
 */
final class FiscalMonitoringScheduler
{
    public function __construct(
        private readonly MonitorCommercialLedgerService $commercialLedger,
        private readonly SubscriptionPeriodService $periods,
        private readonly FiscalModuleAvailabilityService $availability,
        private readonly EsocialBxReadinessService $esocialReadiness,
    ) {}

    public function isCoreEnabled(): bool
    {
        if ((bool) config('fiscal.kill_switch', false) || (bool) config('fiscal_monitoring.kill_switch', false)) {
            return false;
        }

        return (bool) config('fiscal_monitoring.scheduler.enabled', false)
            || (bool) config('fiscal_monitoring.enabled', false);
    }

    public function isCommercialMonthlyEnabled(): bool
    {
        return $this->isCoreEnabled()
            && (bool) config('fiscal_monitoring.scheduler.commercial_monthly_enabled', false);
    }

    /**
     * Minuto preferencial determinístico 0–59 (office + client + system + service).
     */
    public function preferredMinute(int $officeId, int $clientId, string $systemCode, string $serviceCode): int
    {
        $spread = max(1, (int) config('fiscal_monitoring.scheduler.spread_minutes', 60));
        $material = "{$officeId}|{$clientId}|{$systemCode}|{$serviceCode}";
        $hash = crc32($material);

        return (int) ($hash % $spread);
    }

    public function firstRunAt(int $preferredMinute, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $from ??= CarbonImmutable::now();
        $candidate = $from->startOfHour()->addMinutes($preferredMinute);
        if ($candidate->lessThanOrEqualTo($from)) {
            $candidate = $candidate->addHour();
        }

        return $candidate;
    }

    public function nextRunAfter(FiscalMonitoringSchedule $schedule, ?CarbonImmutable $from = null): CarbonImmutable
    {
        $from ??= CarbonImmutable::now();
        $interval = max(1, (int) $schedule->interval_minutes);
        $preferred = (int) $schedule->preferred_minute;
        $base = $from->addMinutes($interval);
        $aligned = $base->startOfHour()->addMinutes($preferred % 60);
        if ($aligned->lessThanOrEqualTo($from)) {
            $aligned = $aligned->addHour();
        }

        // Se intervalo > 60, respeita pelo menos o intervalo a partir de now
        if ($aligned->lessThan($from->addMinutes($interval))) {
            $aligned = $from->addMinutes($interval);
        }

        return $aligned;
    }

    /**
     * Dispara agendas devidas com fairness round-robin por office.
     * Quando commercial_monthly_enabled: prioriza política mensal + spillover.
     *
     * @return array{dispatched:int,skipped:int,blocked:int,commercial_created:int}
     */
    public function dispatchDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $dispatched = 0;
        $skipped = 0;
        $blocked = 0;
        $commercialCreated = 0;

        if (! $this->isCoreEnabled()) {
            return [
                'dispatched' => 0,
                'skipped' => 0,
                'blocked' => 0,
                'commercial_created' => 0,
            ];
        }

        if ($this->isCommercialMonthlyEnabled()) {
            $commercial = $this->dispatchCommercialMonthlyDue($now);
            $dispatched += $commercial['dispatched'];
            $skipped += $commercial['skipped'];
            $blocked += $commercial['blocked'];
            $commercialCreated += $commercial['commercial_created'];
        }

        $legacy = $this->dispatchLegacyIntervalDue($now);
        $dispatched += $legacy['dispatched'];
        $skipped += $legacy['skipped'];
        $blocked += $legacy['blocked'];

        return compact('dispatched', 'skipped', 'blocked') + ['commercial_created' => $commercialCreated];
    }

    /**
     * Política mensal: dia 1–28 por office+monitor, default hash estável, spillover nos dias seguintes.
     * Um item comercial scheduled por cliente+monitor+período; consumo só no despacho remoto real.
     *
     * @return array{dispatched:int,skipped:int,blocked:int,commercial_created:int}
     */
    public function dispatchCommercialMonthlyDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $dispatched = 0;
        $skipped = 0;
        $blocked = 0;
        $commercialCreated = 0;

        if (! $this->isCommercialMonthlyEnabled()) {
            return [
                'dispatched' => 0,
                'skipped' => 0,
                'blocked' => 0,
                'commercial_created' => 0,
            ];
        }

        $max = max(1, (int) config('fiscal_monitoring.scheduler.max_dispatch_per_tick', 40));
        $monitorKeys = CommercialMonitorCatalog::all();

        $subscriptions = OfficeSubscription::query()
            ->orderBy('office_id')
            ->get()
            ->keyBy('office_id');

        foreach ($subscriptions as $officeId => $subscription) {
            if ($dispatched >= $max) {
                break;
            }

            if (! $subscription->status->allowsExternalCalls()) {
                continue;
            }

            $office = Office::query()->find($officeId);
            if ($office === null || ! $office->is_active) {
                continue;
            }

            $tz = $office->timezone ?: $office->deadline_timezone ?: 'America/Sao_Paulo';
            $local = $now->timezone($tz);
            $localDay = (int) $local->day;

            $this->periods->ensureCurrent($subscription, $now);

            foreach ($monitorKeys as $monitorKey) {
                if ($dispatched >= $max) {
                    break;
                }

                $decision = $this->availability->resolve(
                    FiscalControlModule::fromRuntimeKey($monitorKey),
                    $office,
                    FiscalOperationClass::Read,
                );
                if (! $decision->allowed) {
                    $blocked++;

                    continue;
                }

                $policy = OfficeMonitorSchedulePolicy::ensureDefault((int) $officeId, $monitorKey);
                $dueDay = (int) $policy->day_of_month;

                // Due no dia configurado ou spillover (dias posteriores até 28+resto do mês).
                $isDueDay = $localDay >= $dueDay;
                if (! $isDueDay) {
                    // Ainda permite spillover de itens pending já criados no período.
                    $hasPending = MonitorCommercialLedgerEntry::query()
                        ->withoutGlobalScopes()
                        ->where('office_id', $officeId)
                        ->where('monitor_key', $monitorKey)
                        ->whereIn('origin', [
                            MonitorCommercialOrigin::Scheduled->value,
                            MonitorCommercialOrigin::Inaugural->value,
                        ])
                        ->where('dispatch_state', MonitorCommercialDispatchState::Pending)
                        ->exists();
                    if (! $hasPending) {
                        continue;
                    }
                }

                $clients = $this->eligibleClientsForMonitor((int) $officeId, $monitorKey);
                foreach ($clients as $clientId) {
                    if ($dispatched >= $max) {
                        break;
                    }

                    $beforeId = MonitorCommercialLedgerEntry::query()
                        ->withoutGlobalScopes()
                        ->where('office_id', $officeId)
                        ->where('client_id', $clientId)
                        ->where('monitor_key', $monitorKey)
                        ->whereIn('origin', [
                            MonitorCommercialOrigin::Scheduled->value,
                            MonitorCommercialOrigin::Inaugural->value,
                        ])
                        ->where('period_key', $this->periods->resolve($subscription, $now)['period_key'])
                        ->value('id');

                    $entry = $this->commercialLedger->ensureScheduledItem(
                        (int) $officeId,
                        (int) $clientId,
                        $monitorKey,
                        $subscription,
                        $now,
                    );

                    if ($beforeId === null) {
                        $commercialCreated++;
                    }

                    if ($entry->dispatch_state !== MonitorCommercialDispatchState::Pending) {
                        $skipped++;

                        continue;
                    }

                    // Saldo esgotado por manuais → bloqueia sem SERPRO (inaugural ainda free).
                    $balance = $this->commercialLedger->balance(
                        (int) $officeId,
                        (int) $clientId,
                        $monitorKey,
                        $subscription,
                        $now,
                    );
                    $freeInaugural = $entry->origin === MonitorCommercialOrigin::Inaugural
                        || $balance['inaugural_available'];
                    if ($balance['remaining'] <= 0 && ! $freeInaugural) {
                        $this->commercialLedger->markBlockedQuota($entry);
                        $blocked++;

                        continue;
                    }

                    $outcome = $this->enqueueCommercialScheduledRun(
                        (int) $officeId,
                        (int) $clientId,
                        $monitorKey,
                        $entry,
                        $now,
                    );

                    if ($outcome === 'dispatched') {
                        $dispatched++;
                    } elseif ($outcome === 'blocked') {
                        $blocked++;
                    } else {
                        $skipped++;
                    }
                }
            }
        }

        return compact('dispatched', 'skipped', 'blocked') + ['commercial_created' => $commercialCreated];
    }

    /**
     * Path legado: interval_minutes + preferred_minute por cliente.
     *
     * @return array{dispatched:int,skipped:int,blocked:int}
     */
    public function dispatchLegacyIntervalDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $dispatched = 0;
        $skipped = 0;
        $blocked = 0;

        if (! $this->isCoreEnabled()) {
            return compact('dispatched', 'skipped', 'blocked');
        }

        // Com mensal comercial ativo, agendas intervalares de monitores comerciais ficam pausadas
        // (evita segunda execução no mesmo período).
        $pauseCommercialLegacy = $this->isCommercialMonthlyEnabled();

        $max = max(1, (int) config('fiscal_monitoring.scheduler.max_dispatch_per_tick', 40));
        $minute = (int) $now->format('i');

        $byOffice = FiscalMonitoringSchedule::query()
            ->withoutGlobalScopes()
            ->where('is_enabled', true)
            ->where(function ($q) use ($now): void {
                $q->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now);
            })
            ->orderBy('office_id')
            ->orderBy('id')
            ->get()
            ->groupBy('office_id');

        if ($byOffice->isEmpty()) {
            return compact('dispatched', 'skipped', 'blocked');
        }

        $officeIds = $byOffice->keys()->values()->all();
        $pointers = array_fill_keys($officeIds, 0);
        $exhausted = [];

        while ($dispatched < $max && count($exhausted) < count($officeIds)) {
            foreach ($officeIds as $officeId) {
                if ($dispatched >= $max) {
                    break;
                }
                if (isset($exhausted[$officeId])) {
                    continue;
                }

                /** @var Collection<int, FiscalMonitoringSchedule> $schedules */
                $schedules = $byOffice[$officeId];
                $idx = $pointers[$officeId];
                if ($idx >= $schedules->count()) {
                    $exhausted[$officeId] = true;

                    continue;
                }

                /** @var FiscalMonitoringSchedule $schedule */
                $schedule = $schedules[$idx];
                $pointers[$officeId] = $idx + 1;

                if ($pauseCommercialLegacy) {
                    $monitorKey = CommercialMonitorCatalog::resolveMonitorKey(
                        $schedule->system_code,
                        $schedule->service_code,
                    );
                    if ($monitorKey !== null) {
                        $skipped++;

                        continue;
                    }
                }

                if ($schedule->next_run_at === null && (int) $schedule->preferred_minute !== $minute) {
                    $skipped++;

                    continue;
                }

                if (! $this->officeAllowsExternal((int) $officeId)) {
                    $blocked++;
                    $schedule->forceFill([
                        'last_skip_reason' => 'SUBSCRIPTION_BLOCKED',
                        'next_run_at' => $this->nextRunAfter($schedule, $now),
                    ])->save();

                    continue;
                }

                $module = $this->moduleForSchedule($schedule);
                $office = Office::query()->find((int) $officeId);
                if ($module !== null && ($office === null || ! $this->availability
                    ->resolve($module, $office, FiscalOperationClass::Read)->allowed)) {
                    $blocked++;
                    $schedule->forceFill([
                        'last_skip_reason' => 'MODULE_UNAVAILABLE',
                        'next_run_at' => $this->nextRunAfter($schedule, $now),
                    ])->save();

                    continue;
                }

                $outcome = $this->claimAndEnqueue($schedule, $now);
                if ($outcome === 'dispatched') {
                    $dispatched++;
                } elseif ($outcome === 'blocked') {
                    $blocked++;
                } else {
                    $skipped++;
                }
            }
        }

        return compact('dispatched', 'skipped', 'blocked');
    }

    /**
     * Clientes elegíveis ao monitor: apenas carteira com agenda habilitada para o serviço
     * (ativação explícita do monitor). Sem schedule → lista vazia (fail-closed).
     *
     * @return list<int>
     */
    private function eligibleClientsForMonitor(int $officeId, string $monitorKey): array
    {
        $serviceCodes = match ($monitorKey) {
            'sitfis' => ['SITFIS'],
            'simples_mei' => ['PGDASD', 'PGMEI', 'SIMPLES', 'SIMPLES_NACIONAL', 'MEI'],
            'dctfweb' => ['DCTFWEB', 'MIT'],
            'mailbox' => ['CAIXA_POSTAL', 'MAILBOX', 'MSGNACIONAL'],
            'fgts' => ['FGTS', 'ESOCIAL'],
            'installments' => ['INSTALLMENTS', 'PARCELAMENTO'],
            'declarations' => ['DECLARATIONS', 'DECLARACAO'],
            'guides' => ['GUIDES', 'GUIAS'],
            'registrations' => ['REGISTRATIONS', 'CADIN'],
            'tax_processes' => ['TAX_PROCESSES', 'PROCESSOS'],
            default => [strtoupper($monitorKey)],
        };

        return FiscalMonitoringSchedule::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('is_enabled', true)
            ->whereIn('service_code', $serviceCodes)
            ->orderBy('client_id')
            ->pluck('client_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return 'dispatched'|'skipped'|'blocked'
     */
    private function enqueueCommercialScheduledRun(
        int $officeId,
        int $clientId,
        string $monitorKey,
        MonitorCommercialLedgerEntry $entry,
        CarbonImmutable $now,
    ): string {
        $office = Office::query()->find($officeId);
        if ($office === null || ! $this->availability->resolve(
            FiscalControlModule::fromRuntimeKey($monitorKey),
            $office,
            FiscalOperationClass::Read,
        )->allowed) {
            return 'blocked';
        }

        if ($monitorKey === 'fgts' && $this->esocialReadinessBlocker($office, $clientId) !== null) {
            return 'blocked';
        }

        if ($monitorKey === 'installments') {
            return $this->enqueueCommercialInstallmentRuns($officeId, $clientId, $entry);
        }

        $systemService = match ($monitorKey) {
            'sitfis' => ['INTEGRA_SITFIS', 'SITFIS', 'MONITOR'],
            'simples_mei' => $this->simplesMeiSystemServiceForClient($officeId, $clientId),
            'dctfweb' => ['DCTFWEB', 'DCTFWEB', 'MONITOR'],
            'mailbox' => ['CAIXA_POSTAL', 'CAIXA_POSTAL', 'MONITOR'],
            'fgts' => ['ESOCIAL', 'FGTS', 'MONITOR'],
            default => [strtoupper($monitorKey), strtoupper($monitorKey), 'MONITOR'],
        };

        [$systemCode, $serviceCode, $operationCode] = $systemService;

        $slot = 'commercial-period:'.$entry->period_key.':'.$entry->id;
        $key = FiscalIdempotency::runKey(
            $officeId,
            $clientId,
            $systemCode,
            $serviceCode,
            $operationCode,
            null,
            FiscalTrigger::Scheduled,
            $slot,
        );

        $existing = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing !== null) {
            return 'skipped';
        }

        $sitfisDriver = null;
        if ($monitorKey === 'sitfis') {
            $sitfisDriver = app(CapabilityDriverResolver::class)->forCapability('sitfis');
            if ($sitfisDriver === SerproCapabilityDriver::Disabled) {
                return 'blocked';
            }
        }

        $run = FiscalMonitoringRun::query()->create([
            'office_id' => $officeId,
            'client_id' => $clientId,
            'system_code' => $systemCode,
            'service_code' => $serviceCode,
            'operation_code' => $operationCode,
            'operation_key' => $sitfisDriver !== null ? 'sitfis.emitir_relatorio' : null,
            'source_provenance' => $sitfisDriver === SerproCapabilityDriver::Real
                ? FiscalSourceProvenance::SerproReal
                : null,
            'verification_state' => $sitfisDriver !== null
                ? FiscalVerificationState::Unverified
                : null,
            'trigger' => FiscalTrigger::Scheduled,
            'idempotency_key' => $key,
            'status' => FiscalRunStatus::Queued,
            'situation' => 'UNKNOWN',
            'coverage' => 'UNKNOWN',
            'mutability' => 'READ_ONLY',
            'correlation_id' => bin2hex(random_bytes(8)),
            // PGDAS primeiro; period_key comercial por cima — não sobrescrever o ledger
            // (assinatura = data YYYY-MM-DD) com o PA fiscal (YYYY-MM).
            'progress' => array_merge(
                $this->monitorProgressForCodes($systemCode, $serviceCode, $officeId, $now),
                [
                    'commercial_ledger_entry_id' => $entry->id,
                    'monitor_key' => $monitorKey,
                    'commercial_origin' => $entry->origin->value,
                    'period_key' => $entry->period_key,
                ],
            ),
        ]);

        // Metadata no ledger (sem mutar identidade).
        $meta = is_array($entry->metadata) ? $entry->metadata : [];
        $meta['fiscal_monitoring_run_id'] = $run->id;
        $entry->forceFill(['metadata' => $meta])->save();

        ExecuteFiscalMonitoringRunJob::dispatch($run->id)
            ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));

        return 'dispatched';
    }

    /**
     * O item comercial de Parcelamentos representa o pacote; tecnicamente ele se
     * desdobra nas oito modalidades produtivas, cada uma com idempotência própria.
     *
     * @return 'dispatched'|'skipped'
     */
    private function enqueueCommercialInstallmentRuns(
        int $officeId,
        int $clientId,
        MonitorCommercialLedgerEntry $entry,
    ): string {
        $slot = 'commercial-period:'.$entry->period_key.':'.$entry->id;
        $correlation = bin2hex(random_bytes(8));
        $runIds = [];
        $createdIds = [];

        foreach (ParcelamentoServiceCatalog::supportedModalities() as $modality) {
            $key = FiscalIdempotency::runKey(
                $officeId,
                $clientId,
                ParcelamentoServiceCatalog::SOLUTION,
                $modality->value,
                'MONITOR',
                null,
                FiscalTrigger::Scheduled,
                $slot,
            );

            $run = FiscalMonitoringRun::query()
                ->withoutGlobalScopes()
                ->where('office_id', $officeId)
                ->where('idempotency_key', $key)
                ->first();

            if ($run === null) {
                $run = FiscalMonitoringRun::query()->create([
                    'office_id' => $officeId,
                    'client_id' => $clientId,
                    'system_code' => ParcelamentoServiceCatalog::SOLUTION,
                    'service_code' => $modality->value,
                    'operation_code' => 'MONITOR',
                    'trigger' => FiscalTrigger::Scheduled,
                    'idempotency_key' => $key,
                    'status' => FiscalRunStatus::Queued,
                    'situation' => 'UNKNOWN',
                    'coverage' => 'UNKNOWN',
                    'mutability' => 'READ_ONLY',
                    'correlation_id' => $correlation.':'.strtolower(str_replace('-', '_', $modality->value)),
                    'progress' => [
                        'commercial_ledger_entry_id' => $entry->id,
                        'monitor_key' => 'installments',
                        'commercial_origin' => $entry->origin->value,
                        'period_key' => $entry->period_key,
                    ],
                ]);
                $createdIds[] = (int) $run->id;
            }

            $runIds[] = (int) $run->id;
        }

        if ($createdIds === []) {
            return 'skipped';
        }

        $metadata = is_array($entry->metadata) ? $entry->metadata : [];
        $metadata['fiscal_monitoring_run_ids'] = $runIds;
        $metadata['fiscal_monitoring_run_id'] = $runIds[0];
        $entry->forceFill(['metadata' => $metadata])->save();

        foreach ($createdIds as $runId) {
            ExecuteFiscalMonitoringRunJob::dispatch($runId)
                ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
        }

        return 'dispatched';
    }

    public function officeAllowsExternal(int $officeId): bool
    {
        $sub = OfficeSubscription::query()->where('office_id', $officeId)->first();
        if ($sub === null) {
            return false;
        }

        return $sub->status->allowsExternalCalls();
    }

    /**
     * @return 'dispatched'|'skipped'|'blocked'
     */
    public function claimAndEnqueue(FiscalMonitoringSchedule $schedule, CarbonImmutable $now): string
    {
        $office = Office::query()->find((int) $schedule->office_id);
        $module = $this->moduleForSchedule($schedule);
        if ($module !== null && ($office === null || ! $this->availability
            ->resolve($module, $office, FiscalOperationClass::Read)->allowed)) {
            $schedule->forceFill([
                'last_skip_reason' => 'MODULE_UNAVAILABLE',
                'next_run_at' => $this->nextRunAfter($schedule, $now),
            ])->save();

            return 'blocked';
        }

        if ($module === FiscalControlModule::Fgts && $office !== null) {
            $blocker = $this->esocialReadinessBlocker($office, (int) $schedule->client_id);
            if ($blocker !== null) {
                $schedule->forceFill([
                    'last_skip_reason' => $blocker,
                    'next_run_at' => $this->nextRunAfter($schedule, $now),
                ])->save();

                return 'blocked';
            }
        }

        $run = null;

        $created = DB::transaction(function () use ($schedule, $now, &$run) {
            $locked = FiscalMonitoringSchedule::query()
                ->withoutGlobalScopes()
                ->whereKey($schedule->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $locked->is_enabled) {
                return 'skipped';
            }

            // Scheduler NUNCA cria intenção mutante (fail-closed).
            if ($this->looksMutating($locked)) {
                $locked->forceFill([
                    'next_run_at' => $this->nextRunAfter($locked, $now),
                    'last_skip_reason' => 'MUTATING_NOT_SCHEDULED',
                ])->save();

                return 'blocked';
            }

            $sitfisDriver = null;
            if (strtoupper((string) $locked->service_code) === 'SITFIS') {
                $sitfisDriver = app(CapabilityDriverResolver::class)->forCapability('sitfis');
                if ($sitfisDriver === SerproCapabilityDriver::Disabled) {
                    return 'blocked';
                }
            }
            if ($locked->next_run_at !== null && $locked->next_run_at->greaterThan($now)) {
                return 'skipped';
            }
            $idempotencyCodes = $this->isPgmeiCodes(
                (string) $locked->system_code,
                (string) $locked->service_code,
            )
                ? ['INTEGRA_MEI', 'PGMEI', 'MONITOR']
                : [
                    (string) $locked->system_code,
                    (string) $locked->service_code,
                    (string) $locked->operation_code,
                ];

            $slot = $this->scheduledSlot($locked, $now);
            $key = FiscalIdempotency::runKey(
                (int) $locked->office_id,
                (int) $locked->client_id,
                $idempotencyCodes[0],
                $idempotencyCodes[1],
                $idempotencyCodes[2],
                null,
                FiscalTrigger::Scheduled,
                $slot,
            );

            $existing = FiscalMonitoringRun::query()
                ->withoutGlobalScopes()
                ->where('office_id', $locked->office_id)
                ->where('idempotency_key', $key)
                ->first();

            if ($existing !== null) {
                $locked->forceFill([
                    'next_run_at' => $this->nextRunAfter($locked, $now),
                    'last_skip_reason' => 'IDEMPOTENT_REPLAY',
                ])->save();

                return 'skipped';
            }

            $run = FiscalMonitoringRun::query()->create([
                'office_id' => $locked->office_id,
                'client_id' => $locked->client_id,
                'fiscal_category_id' => $locked->fiscal_category_id,
                'schedule_id' => $locked->id,
                'system_code' => $locked->system_code,
                'service_code' => $locked->service_code,
                'operation_code' => $locked->operation_code,
                'operation_key' => $sitfisDriver !== null ? 'sitfis.emitir_relatorio' : null,
                'source_provenance' => $sitfisDriver === SerproCapabilityDriver::Real
                    ? FiscalSourceProvenance::SerproReal
                    : null,
                'verification_state' => $sitfisDriver !== null
                    ? FiscalVerificationState::Unverified
                    : null,
                'trigger' => FiscalTrigger::Scheduled,
                'idempotency_key' => $key,
                'status' => FiscalRunStatus::Queued,
                'situation' => 'UNKNOWN',
                'coverage' => 'UNKNOWN',
                'mutability' => 'READ_ONLY',
                'correlation_id' => bin2hex(random_bytes(8)),
                'progress' => $this->monitorProgressForCodes(
                    (string) $locked->system_code,
                    (string) $locked->service_code,
                    (int) $locked->office_id,
                    $now,
                ) ?: null,
            ]);

            $locked->forceFill([
                'last_run_at' => $now,
                'next_run_at' => $this->nextRunAfter($locked, $now),
                'last_skip_reason' => null,
            ])->save();

            return 'dispatched';
        });

        if ($created === 'dispatched' && $run !== null) {
            ExecuteFiscalMonitoringRunJob::dispatch($run->id)
                ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
        }

        return $created;
    }

    private function moduleForSchedule(FiscalMonitoringSchedule $schedule): ?FiscalControlModule
    {
        $monitorKey = CommercialMonitorCatalog::resolveMonitorKey(
            (string) $schedule->system_code,
            (string) $schedule->service_code,
        );

        return $monitorKey === null ? null : FiscalControlModule::fromRuntimeKey($monitorKey);
    }

    private function esocialReadinessBlocker(Office $office, int $clientId): ?string
    {
        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($clientId)
            ->first();

        if ($client === null) {
            return 'ESOCIAL_BX_CLIENT_NOT_FOUND';
        }

        $readiness = $this->esocialReadiness->check($office, $client);

        return $readiness->ready
            ? null
            : ($readiness->blockers[0]['code'] ?? 'ESOCIAL_BX_NOT_READY');
    }

    /**
     * Progresso por serviço: PGDAS-D congela PA; PGMEI alterna um dos 5 anos recentes por ciclo diário.
     *
     * @return array<string, mixed>
     */
    private function progressForCodes(string $systemCode, string $serviceCode, int $officeId, CarbonImmutable $now): array
    {
        $svc = strtoupper($serviceCode);
        $sys = strtoupper($systemCode);

        $office = Office::query()->find($officeId);
        $tz = is_string($office?->timezone) && $office->timezone !== ''
            ? $office->timezone
            : 'America/Sao_Paulo';

        if ($svc === 'PGMEI' || $sys === 'PGMEI' || ($sys === 'INTEGRA_MEI' && $svc === 'PGMEI')) {
            $year = PgmeiYear::yearForDailyCycle($now, $tz);

            return [
                'ano_calendario' => (string) $year,
                'anoCalendario' => (string) $year,
                'period_key' => PgmeiYear::toPeriodKey($year),
                'pgmei_year_frozen_at' => $now->toIso8601String(),
            ];
        }

        $isDctfweb = $svc === 'DCTFWEB'
            || $sys === 'DCTFWEB'
            || $sys === 'INTEGRA_DCTFWEB'
            || str_contains(strtolower($serviceCode), 'dctfweb');

        if ($isDctfweb) {
            $pa = DctfwebPeriod::expectedPa($now, $tz);

            return [
                'expected_period_key' => DctfwebPeriod::toPeriodKey($pa),
                'expected_periodo_apuracao' => DctfwebPeriod::toPeriodoApuracao($pa),
                'period_key' => DctfwebPeriod::toPeriodKey($pa),
                'anoPA' => DctfwebPeriod::toAnoPa($pa),
                'mesPA' => DctfwebPeriod::toMesPa($pa),
                'categoria' => DctfwebCategory::default()->officialCode(),
                'category' => DctfwebCategory::default()->value,
                'operation_key' => 'dctfweb.consrecibo',
                'dctfweb_pa_frozen_at' => $now->toIso8601String(),
            ];
        }

        $isPgdasd = $svc === 'PGDASD'
            || ($sys === 'INTEGRA_SN' && $svc === 'PGDASD')
            || $sys === 'PGDASD';

        if (! $isPgdasd && ! in_array($svc, ['PGDASD', 'SIMPLES'], true)) {
            if (! str_contains(strtolower($serviceCode), 'pgdas')) {
                return [];
            }
        }

        $pa = PgdasdPeriod::expectedPa($now, $tz);

        return [
            'expected_periodo_apuracao' => PgdasdPeriod::toPeriodoApuracao($pa),
            'period_key' => PgdasdPeriod::toPeriodKey($pa),
            'ano_calendario' => PgdasdPeriod::yearFromPa($pa),
            'pgdasd_pa_frozen_at' => $now->toIso8601String(),
        ];
    }

    /** @deprecated use progressForCodes */
    private function pgdasdProgressForCodes(string $systemCode, string $serviceCode, int $officeId, CarbonImmutable $now): array
    {
        return $this->progressForCodes($systemCode, $serviceCode, $officeId, $now);
    }

    /**
     * Congela o contexto fiscal antes de enfileirar. Cada execução PGMEI observa
     * exatamente um dos cinco anos-calendário mais recentes.
     *
     * @return array<string, mixed>
     */
    private function monitorProgressForCodes(
        string $systemCode,
        string $serviceCode,
        int $officeId,
        CarbonImmutable $now,
    ): array {
        if ($this->isPgmeiCodes($systemCode, $serviceCode)) {
            $office = Office::query()->find($officeId);
            $tz = is_string($office?->timezone) && $office->timezone !== ''
                ? $office->timezone
                : 'America/Sao_Paulo';
            $local = $now->timezone($tz);
            $year = ((int) $local->format('Y')) - (((int) $local->format('z')) % 5);

            return [
                'query_year' => $year,
                'ano_calendario' => (string) $year,
                'period_key' => (string) $year,
                'pgmei_year_frozen_at' => $now->toIso8601String(),
            ];
        }

        // DCTFWeb e PGDAS-D: progressForCodes já congela PA sem sobrescrita comercial.
        return $this->progressForCodes($systemCode, $serviceCode, $officeId, $now);
    }

    /**
     * PGMEI é diário: duas agendas equivalentes do mesmo cliente não podem
     * produzir duas chamadas no mesmo ciclo local.
     */
    private function scheduledSlot(FiscalMonitoringSchedule $schedule, CarbonImmutable $now): string
    {
        if (! $this->isPgmeiCodes((string) $schedule->system_code, (string) $schedule->service_code)) {
            return FiscalIdempotency::scheduledSlot($now);
        }

        $office = Office::query()->find((int) $schedule->office_id);
        $tz = is_string($office?->timezone) && $office->timezone !== ''
            ? $office->timezone
            : 'America/Sao_Paulo';

        return 'pgmei-day:'.$now->timezone($tz)->format('Ymd');
    }

    private function isPgmeiCodes(string $systemCode, string $serviceCode): bool
    {
        $system = strtoupper(trim($systemCode));
        $service = strtoupper(trim($serviceCode));

        return $service === 'PGMEI'
            || $service === 'MEI'
            || $system === 'PGMEI'
            || ($system === 'INTEGRA_MEI' && $service === 'PGMEI');
    }

    /**
     * O monitor comercial compartilhado respeita o regime interno do cliente;
     * ele não transforma MEI em consulta PGDAS-D nem o inverso.
     *
     * @return array{0:string,1:string,2:string}
     */
    private function simplesMeiSystemServiceForClient(int $officeId, int $clientId): array
    {
        $rawRegime = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereKey($clientId)
            ->value('tax_regime');

        if (TaxRegimeCode::normalize(is_string($rawRegime) ? $rawRegime : null)->isMeiFamily()) {
            return ['INTEGRA_MEI', 'PGMEI', 'MONITOR'];
        }

        return ['INTEGRA_SN', 'PGDASD', 'MONITOR'];
    }

    /**
     * Heurística fail-closed: códigos de operação tipicamente mutantes não entram no scheduler.
     */
    private function looksMutating(FiscalMonitoringSchedule $schedule): bool
    {
        $meta = is_array($schedule->metadata) ? $schedule->metadata : [];
        $flag = strtoupper((string) ($meta['mutability'] ?? $meta['is_mutating'] ?? ''));
        if (in_array($flag, ['MUTATING', 'WRITE', 'MUTATION', '1', 'TRUE'], true)) {
            return true;
        }

        $op = strtoupper((string) $schedule->operation_code);
        $mutatingOps = [
            'TRANSMITIR', 'TRANSMITIR_DECLARACAO', 'TRANSDECLARACAO',
            'EMITIR_GUIA', 'GERARGUIA', 'GERARDAS', 'GERAR_DAS',
            'ENCERRAR', 'ENCAPURACAO', 'ADERIR', 'EFETUAROPCAOREGIME',
            'GERARDASPDF21', 'GERARDASCODBARRA22', 'ATUBENEFICIO', 'ATUBENEFICIO23',
            'SOLICRENUNCIA',
        ];

        return in_array($op, $mutatingOps, true);
    }

    public function globalSlotAvailable(): bool
    {
        $limit = max(1, (int) config('fiscal_monitoring.scheduler.global_concurrent_limit', 8));
        $key = FiscalIdempotency::cacheKey(0, 'global-slots');
        $current = (int) Cache::get($key, 0);

        return $current < $limit;
    }

    public function tenantSlotAvailable(int $officeId): bool
    {
        $limit = max(1, (int) config('fiscal_monitoring.scheduler.tenant_concurrent_limit', 2));
        $key = FiscalIdempotency::cacheKey($officeId, 'tenant-slots');
        $current = (int) Cache::get($key, 0);

        return $current < $limit;
    }

    /**
     * Reserva slots global+tenant sob lock (claim atômico — evita TOCTOU entre workers).
     *
     * @return array{global:string,tenant:string}|null
     */
    public function acquireSlots(int $officeId, int $ttlSeconds): ?array
    {
        $lock = Cache::lock('fiscal-monitoring:slot-claim', 10);

        try {
            return $lock->block(5, function () use ($officeId, $ttlSeconds) {
                $globalLimit = max(1, (int) config('fiscal_monitoring.scheduler.global_concurrent_limit', 8));
                $tenantLimit = max(1, (int) config('fiscal_monitoring.scheduler.tenant_concurrent_limit', 2));
                $globalKey = FiscalIdempotency::cacheKey(0, 'global-slots');
                $tenantKey = FiscalIdempotency::cacheKey($officeId, 'tenant-slots');

                $globalCurrent = (int) Cache::get($globalKey, 0);
                $tenantCurrent = (int) Cache::get($tenantKey, 0);

                if ($globalCurrent >= $globalLimit || $tenantCurrent >= $tenantLimit) {
                    return null;
                }

                // Put com valor computado sob o mesmo lock (sem increment+put stale).
                Cache::put($globalKey, $globalCurrent + 1, $ttlSeconds);
                Cache::put($tenantKey, $tenantCurrent + 1, $ttlSeconds);

                return ['global' => $globalKey, 'tenant' => $tenantKey];
            });
        } catch (LockTimeoutException) {
            return null;
        }
    }

    public function releaseSlots(?array $keys): void
    {
        if ($keys === null) {
            return;
        }
        foreach (['global', 'tenant'] as $k) {
            if (! isset($keys[$k])) {
                continue;
            }
            $key = $keys[$k];
            $val = (int) Cache::get($key, 0);
            if ($val <= 1) {
                Cache::forget($key);
            } else {
                Cache::decrement($key);
            }
        }
    }
}

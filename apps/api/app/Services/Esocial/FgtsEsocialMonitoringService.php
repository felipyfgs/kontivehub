<?php

namespace App\Services\Esocial;

use App\Contracts\EsocialEventClient;
use App\DTO\Esocial\EsocialFetchRequest;
use App\DTO\Esocial\FgtsCompetenceProjection;
use App\Enums\EsocialEventCode;
use App\Enums\FgtsIndependentState;
use App\Enums\FiscalCoverage;
use App\Exceptions\EsocialBxException;
use App\Models\Client;
use App\Models\EsocialEventEvidence;
use App\Models\Establishment;
use App\Models\FgtsCompetenceStatus;
use App\Models\FiscalCategory;
use App\Models\FiscalCompetence;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Orquestra consulta eSocial (fake/M2M), persistência de evidências e projeção de estados FGTS.
 * Cobertura sempre parcial; limitações explícitas em toda resposta pública.
 */
final class FgtsEsocialMonitoringService
{
    public function __construct(
        private readonly EsocialEventClient $client,
        private readonly EsocialEvidencePersistence $evidencePersistence,
        private readonly FgtsIndependentStateProjector $projector,
        private readonly FgtsEsocialDivergenceAnalyzer $divergenceAnalyzer,
    ) {}

    /**
     * Indica se o container recebeu uma fonte M2M explicitamente instalada.
     * O binding padrão é Disabled em todos os ambientes, inclusive testing.
     */
    public function isSourceAvailable(): bool
    {
        return ! $this->client instanceof DisabledEsocialEventClient;
    }

    public function sourceUnavailableMessage(): string
    {
        return $this->client instanceof DisabledEsocialEventClient
            ? $this->client->unavailableMessage()
            : DisabledEsocialEventClient::UNAVAILABLE_MESSAGE;
    }

    public function sourceUnavailableCode(): string
    {
        return $this->client instanceof DisabledEsocialEventClient
            ? $this->client->unavailableCode()
            : 'ESOCIAL_SOURCE_UNAVAILABLE';
    }

    /**
     * Metadados de cobertura/limitações (texto de produto, não só tooltip).
     *
     * @return array<string, mixed>
     */
    public function coverageManifest(): array
    {
        return [
            'module' => 'fgts',
            'coverage' => FiscalCoverage::Partial->value,
            'coverage_label' => (string) config('fgts_esocial.coverage_label', 'FGTS (parcial eSocial)'),
            'system_code' => (string) config('fgts_esocial.system_code', 'ESOCIAL'),
            'service_code' => (string) config('fgts_esocial.service_code', 'FGTS'),
            'source' => 'ESOCIAL_BX_OFFICIAL',
            'source_available' => $this->isSourceAvailable(),
            'transport' => 'SOAP_1_1_MTLS',
            'driver' => (string) config('fgts_esocial.driver', 'disabled'),
            'environment' => (string) config('fgts_esocial.environment', 'restricted'),
            'accepted_events' => array_map(
                static fn ($c) => ['code' => $c->value, 'label' => $c->label()],
                EsocialEventCode::supported(),
            ),
            'supported_events' => array_map(
                static fn ($c) => ['code' => $c->value, 'label' => $c->label()],
                EsocialEventCode::supported(),
            ),
            'automatic_events' => array_map(
                static fn ($c) => ['code' => $c->value, 'label' => $c->label()],
                [EsocialEventCode::S1299, EsocialEventCode::S5013],
            ),
            'context_required_events' => [[
                'code' => EsocialEventCode::S5003->value,
                'label' => EsocialEventCode::S5003->label(),
                'required_context' => 'worker_identifier',
            ]],
            'independent_states' => [
                'closure' => 'Fechamento eSocial (S-1299) — independente de guia/pagamento',
                'totalization' => 'Totalização (S-5003/S-5013) — base conhecida',
                'guide' => 'Guia FGTS Digital — UNSUPPORTED (sem API pública)',
                'payment' => 'Pagamento FGTS Digital — UNSUPPORTED (sem API pública)',
            ],
            'limitations' => $this->projector->defaultLimitations(),
            'declares_fgts_digital_debt' => false,
            'scraping_allowed' => false,
            'portal_fallback' => false,
            'totalizer_absence_window_hours' => (int) config('fgts_esocial.totalizer_absence_window_hours', 72),
            'official_limits' => [
                'blocked_days' => config('fgts_esocial.official_bx.blocked_days', range(1, 7)),
                'daily_accesses_per_employer' => (int) config('fgts_esocial.official_bx.daily_access_limit', 10),
                'max_ids_per_download' => (int) config('fgts_esocial.official_bx.batch_limit', 50),
                'minimum_lag_minutes' => (int) config('fgts_esocial.official_bx.minimum_lag_minutes', 60),
                'max_query_interval_days' => (int) config('fgts_esocial.official_bx.max_query_interval_days', 31),
                'parallel_requests_allowed' => false,
            ],
            'documentation_url' => (string) config('fgts_esocial.official_bx.manual_url'),
            'official_links' => [
                'manual' => (string) config('fgts_esocial.official_bx.manual_url'),
                'announcement' => (string) config('fgts_esocial.official_bx.official_announcement_url'),
                'communication_package' => (string) config('fgts_esocial.official_bx.communication_package_url'),
            ],
            'wsdl_sha256' => (array) config('fgts_esocial.official_bx.wsdl_sha256', []),
        ];
    }

    /**
     * Sincroniza uma competência: fetch → evidências → estados independentes → findings.
     *
     * @return array{
     *     projection: FgtsCompetenceProjection,
     *     status: FgtsCompetenceStatus,
     *     evidences: list<EsocialEventEvidence>,
     *     events_count: int
     * }
     */
    public function syncCompetence(
        Office $office,
        Client $client,
        string $competencePeriodKey,
        ?Establishment $establishment = null,
        ?FiscalMonitoringRun $run = null,
        ?CarbonImmutable $now = null,
    ): array {
        $this->assertTenant($office, $client, $establishment);
        $this->assertCompetenceKey($competencePeriodKey);

        if (! $this->isSourceAvailable()) {
            $code = $this->sourceUnavailableCode();

            throw new EsocialBxException(
                preg_match('/^ESOCIAL_BX_[A-Z0-9_]+$/', $code) === 1
                    ? $code
                    : 'ESOCIAL_BX_DISABLED',
                'Integração oficial eSocial BX indisponível.',
                blocked: true,
            );
        }

        if ((bool) config('fgts_esocial.kill_switch', false)) {
            throw new EsocialBxException(
                'ESOCIAL_BX_KILL_SWITCH',
                'Kill switch do eSocial BX ativo.',
                blocked: true,
            );
        }

        $now ??= CarbonImmutable::now();

        $fetch = $this->client->fetchEvents(new EsocialFetchRequest(
            office: $office,
            client: $client,
            competencePeriodKey: $competencePeriodKey,
            establishment: $establishment,
            correlationId: $run?->correlation_id,
        ));

        if (! $fetch->success) {
            $code = is_string($fetch->errorCode)
                && preg_match('/^ESOCIAL_BX_[A-Z0-9_]+$/', $fetch->errorCode) === 1
                    ? $fetch->errorCode
                    : 'ESOCIAL_BX_FETCH_FAILED';
            $officialCode = $fetch->diagnostics['official_code'] ?? null;

            throw new EsocialBxException(
                stableCode: $code,
                message: 'Falha sanitizada ao consultar eventos no eSocial BX.',
                retryable: ($fetch->diagnostics['retryable'] ?? false) === true,
                blocked: ($fetch->diagnostics['blocked'] ?? false) === true,
                officialCode: is_string($officialCode) && preg_match('/^\d{3}$/', $officialCode) === 1
                    ? $officialCode
                    : null,
            );
        }

        $hasSyntheticInput = false;
        foreach ($fetch->events as $event) {
            if ($this->evidencePersistence->isSyntheticEvent($event)) {
                $hasSyntheticInput = true;
                break;
            }
        }

        $evidences = [];
        if (! $fetch->sourceUnsupported && $fetch->events !== []) {
            $evidences = $this->evidencePersistence->persistMany(
                office: $office,
                client: $client,
                events: $fetch->events,
                run: $run,
                establishment: $establishment,
            );
        }

        // Inclui evidências já persistidas (syncs anteriores) para projeção completa.
        $all = $this->evidencePersistence->listForCompetence(
            $office,
            $client,
            $competencePeriodKey,
            $establishment?->id,
        );

        // Doubles sintéticos continuam úteis para exercitar o projetor offline,
        // mas nunca entram na leitura operacional nem promovem competência fiscal.
        $projectionEvidences = $hasSyntheticInput ? $evidences : $all;

        $projection = $this->projector->project(
            competencePeriodKey: $competencePeriodKey,
            evidences: $projectionEvidences,
            now: $now,
            establishmentId: $establishment?->id,
            sourceUnsupported: $fetch->sourceUnsupported,
        );

        $extra = $this->divergenceAnalyzer->analyze($projectionEvidences, $competencePeriodKey);
        if ($extra !== []) {
            $projection = $this->projector->withExtraFindings($projection, $extra);
        }

        $status = $this->upsertStatus(
            $office,
            $client,
            $competencePeriodKey,
            $projection,
            $projectionEvidences,
            $establishment,
            $run,
            $now,
            $hasSyntheticInput,
        );

        return [
            'projection' => $projection,
            'status' => $status,
            'evidences' => $all,
            'events_count' => count($fetch->events),
        ];
    }

    /**
     * @return LengthAwarePaginator<int, FgtsCompetenceStatus>
     */
    public function paginateStatuses(
        Office $office,
        int $perPage = 50,
        ?int $clientId = null,
        ?string $competencePeriodKey = null,
    ): LengthAwarePaginator {
        $q = FgtsCompetenceStatus::query()
            ->withoutGlobalScopes()
            ->operationallyEligible()
            ->where('office_id', $office->id)
            ->orderByDesc('competence_period_key')
            ->orderByDesc('id');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($competencePeriodKey !== null) {
            $q->where('competence_period_key', $competencePeriodKey);
        }

        return $q->paginate($perPage);
    }

    public function findStatusForOffice(Office $office, int $id): ?FgtsCompetenceStatus
    {
        return FgtsCompetenceStatus::query()
            ->withoutGlobalScopes()
            ->operationallyEligible()
            ->where('office_id', $office->id)
            ->whereKey($id)
            ->first();
    }

    /**
     * @return LengthAwarePaginator<int, EsocialEventEvidence>
     */
    public function paginateEvents(
        Office $office,
        int $perPage = 50,
        ?int $clientId = null,
        ?string $competencePeriodKey = null,
        ?string $eventCode = null,
    ): LengthAwarePaginator {
        $q = EsocialEventEvidence::query()
            ->withoutGlobalScopes()
            ->operationallyEligible()
            ->where('office_id', $office->id)
            ->orderByDesc('id');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($competencePeriodKey !== null) {
            $q->where('competence_period_key', $competencePeriodKey);
        }
        if ($eventCode !== null) {
            $q->where('event_code', strtoupper($eventCode));
        }

        return $q->paginate($perPage);
    }

    /**
     * @param  list<EsocialEventEvidence>  $evidences
     */
    private function upsertStatus(
        Office $office,
        Client $client,
        string $competencePeriodKey,
        FgtsCompetenceProjection $projection,
        array $evidences,
        ?Establishment $establishment,
        ?FiscalMonitoringRun $run,
        CarbonImmutable $now,
        bool $isQuarantined = false,
    ): FgtsCompetenceStatus {
        $closureObserved = null;
        $totalizerObserved = null;
        $totalizerDueBy = null;

        foreach ($evidences as $ev) {
            $code = $ev->event_code;
            $at = $ev->occurred_at ?? $ev->observed_at;
            if ($code?->isClosure() && $at !== null) {
                $parsed = $at instanceof CarbonImmutable ? $at : CarbonImmutable::parse((string) $at);
                if ($closureObserved === null || $parsed->lt($closureObserved)) {
                    $closureObserved = $parsed;
                }
            }
            if ($code?->isTotalizer() && $at !== null) {
                $parsed = $at instanceof CarbonImmutable ? $at : CarbonImmutable::parse((string) $at);
                if ($totalizerObserved === null || $parsed->lt($totalizerObserved)) {
                    $totalizerObserved = $parsed;
                }
            }
        }

        if (
            $projection->closureStatus === FgtsIndependentState::Confirmed
            && $closureObserved !== null
            && $projection->totalizationStatus !== FgtsIndependentState::Present
        ) {
            $windowHours = max(1, (int) config('fgts_esocial.totalizer_absence_window_hours', 72));
            $totalizerDueBy = $closureObserved->addHours($windowHours);
        }

        $competence = $isQuarantined
            ? null
            : $this->ensureFiscalCompetence($office, $client, $competencePeriodKey, $projection);

        $attrs = [
            'fiscal_competence_id' => $competence?->id,
            'run_id' => $run?->id,
            'closure_status' => $projection->closureStatus,
            'totalization_status' => $projection->totalizationStatus,
            'guide_status' => $projection->guideStatus,
            'payment_status' => $projection->paymentStatus,
            'coverage' => $projection->coverage,
            'situation' => $projection->situation,
            'closure_observed_at' => $closureObserved,
            'totalizer_observed_at' => $totalizerObserved,
            'totalizer_due_by' => $totalizerDueBy,
            'last_synced_at' => $now,
            'limitations' => $projection->limitations,
            'metadata' => [
                'normalized' => $projection->normalized,
                'findings_codes' => array_map(
                    static fn (array $f) => $f['code'] ?? null,
                    $projection->findings,
                ),
            ],
            'is_quarantined' => $isQuarantined,
            'quarantine_reason' => $isQuarantined ? 'SYNTHETIC_ESOCIAL_TEST_DOUBLE' : null,
            'quarantined_at' => $isQuarantined ? $now : null,
        ];

        return DB::transaction(function () use ($office, $client, $establishment, $competencePeriodKey, $attrs) {
            $existing = FgtsCompetenceStatus::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->where('competence_period_key', $competencePeriodKey)
                ->when(
                    $establishment !== null,
                    fn ($q) => $q->where('establishment_id', $establishment->id),
                    fn ($q) => $q->whereNull('establishment_id'),
                )
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $existing->fill($attrs)->save();

                return $existing->fresh();
            }

            return FgtsCompetenceStatus::query()->create(array_merge($attrs, [
                'office_id' => $office->id,
                'client_id' => $client->id,
                'establishment_id' => $establishment?->id,
                'competence_period_key' => $competencePeriodKey,
            ]));
        });
    }

    private function ensureFiscalCompetence(
        Office $office,
        Client $client,
        string $competencePeriodKey,
        FgtsCompetenceProjection $projection,
    ): ?FiscalCompetence {
        $category = FiscalCategory::query()->where('code', 'FGTS')->first();
        if ($category === null) {
            return null;
        }

        [$year, $month] = array_map('intval', explode('-', $competencePeriodKey));

        return FiscalCompetence::query()->withoutGlobalScopes()->updateOrCreate(
            [
                'office_id' => $office->id,
                'client_id' => $client->id,
                'fiscal_category_id' => $category->id,
                'period_key' => $competencePeriodKey,
            ],
            [
                'period_year' => $year,
                'period_month' => $month,
                'situation' => $projection->situation,
                'coverage' => $projection->coverage,
                'metadata' => [
                    'source' => 'esocial_fgts',
                    'closure_status' => $projection->closureStatus->value,
                    'totalization_status' => $projection->totalizationStatus->value,
                    'guide_status' => $projection->guideStatus->value,
                    'payment_status' => $projection->paymentStatus->value,
                ],
            ],
        );
    }

    private function assertTenant(Office $office, Client $client, ?Establishment $establishment): void
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }
        if ($establishment !== null) {
            if ((int) $establishment->office_id !== (int) $office->id
                || (int) $establishment->client_id !== (int) $client->id) {
                throw new RuntimeException('Estabelecimento não pertence ao cliente/escritório.');
            }
        }
    }

    private function assertCompetenceKey(string $key): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $key)) {
            throw new RuntimeException("Competência inválida: {$key} (esperado YYYY-MM).");
        }
    }
}

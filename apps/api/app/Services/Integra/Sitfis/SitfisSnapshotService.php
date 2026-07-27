<?php

namespace App\Services\Integra\Sitfis;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalOperationClass;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalVerificationState;
use App\Enums\SerproCapabilityDriver;
use App\Models\Client;
use App\Models\FiscalCategory;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Models\Tenant;
use App\Services\Fiscal\Availability\FiscalModuleAvailabilityService;
use App\Services\FiscalMonitoring\FiscalCategoryService;
use App\Services\FiscalMonitoring\FiscalIdempotency;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Services\Serpro\CapabilityDriverResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * TTL/cache SITFIS: devolve snapshot existente com idade; só enfileira nova chamada se expirado.
 */
final class SitfisSnapshotService
{
    public function __construct(
        private readonly FiscalMonitoringRunService $runs,
        private readonly CapabilityDriverResolver $drivers,
        private readonly FiscalCategoryService $categories,
        private readonly FiscalModuleAvailabilityService $availability,
    ) {}

    /**
     * Snapshot atual + metadados de idade/validade (sem nova chamada).
     *
     * @return array{
     *     snapshot: ?FiscalSnapshot,
     *     age_seconds: ?int,
     *     observed_at: ?string,
     *     expires_at: ?string,
     *     ttl_seconds: int,
     *     is_within_ttl: bool,
     *     is_negative_certificate: bool,
     *     disclaimer: ?string,
     *     active_run: ?array<string, mixed>
     * }
     */
    public function current(Tenant $tenant, Client $client): array
    {
        $this->assertTenant($tenant, $client);
        $ttl = $this->ttlSeconds();
        $system = $this->systemCode();
        $service = $this->serviceCode();

        // Preferência: snapshot corrente verificável com evidência (TTL / reuso).
        $verifiedQuery = FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('system_code', $system)
            ->where('service_code', $service)
            ->where('is_current', true)
            ->whereNotNull('evidence_artifact_id');

        $verifiedQuery->where('verification_state', FiscalVerificationState::Verified->value);
        if (app()->environment('production')) {
            $verifiedQuery->where('source_provenance', FiscalSourceProvenance::SerproReal->value);
        } else {
            $verifiedQuery->whereIn('source_provenance', [
                FiscalSourceProvenance::SerproReal->value,
                FiscalSourceProvenance::SerproTrial->value,
            ]);
        }

        $snapshot = $verifiedQuery->orderByDesc('id')->first();
        $displayOnly = false;

        // Fallback de exibição: snapshot is_current (ex. ERROR/UNVERIFIED sem evidência)
        // para a UI não ficar em branco quando a carteira já mostra falha.
        if ($snapshot === null) {
            $fallback = FiscalSnapshot::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('client_id', $client->id)
                ->where('system_code', $system)
                ->where('service_code', $service)
                ->where('is_current', true)
                ->orderByDesc('id')
                ->first();
            if ($fallback !== null) {
                $snapshot = $fallback;
                $displayOnly = true;
            }
        }

        $now = CarbonImmutable::now();
        $age = null;
        $expiresAt = null;
        $within = false;
        $disclaimer = null;
        $isNeg = false;

        if ($snapshot !== null && $snapshot->observed_at !== null) {
            $age = (int) max(0, $snapshot->observed_at->diffInSeconds($now));
            // Fallback de falha não entra no TTL de reuso (sempre permite refresh).
            if (! $displayOnly) {
                $expiresAt = $snapshot->observed_at->addSeconds($ttl);
                $within = $age < $ttl;
            }
            $normalized = $snapshot->normalized ?? [];
            $disclaimer = isset($normalized['disclaimer']) ? (string) $normalized['disclaimer'] : null;
            $isNeg = (bool) ($normalized['is_negative_certificate'] ?? false);
        }

        $activeRun = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('system_code', $system)
            ->where('service_code', $service)
            ->whereIn('status', ['QUEUED', 'RUNNING', 'REQUEUED'])
            ->orderByDesc('id')
            ->first();

        // REQUEUED é terminal no parent; continuação fica QUEUED — também buscar PROCESSING situation
        if ($activeRun === null) {
            $activeRun = FiscalMonitoringRun::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('client_id', $client->id)
                ->where('system_code', $system)
                ->where('service_code', $service)
                ->where('situation', 'PROCESSING')
                ->whereNull('finished_at')
                ->orderByDesc('id')
                ->first();
        }

        $lastFailedRun = null;
        if ($activeRun === null && ($displayOnly || $snapshot === null)) {
            $lastFailedRun = FiscalMonitoringRun::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('client_id', $client->id)
                ->where('system_code', $system)
                ->where('service_code', $service)
                ->whereIn('status', ['FAILED', 'BLOCKED'])
                ->orderByDesc('id')
                ->first();
        }

        return [
            'snapshot' => $snapshot,
            'age_seconds' => $age,
            'observed_at' => $snapshot?->observed_at?->toIso8601String(),
            'expires_at' => $expiresAt?->toIso8601String(),
            'ttl_seconds' => $ttl,
            'is_within_ttl' => $within,
            'is_negative_certificate' => $isNeg,
            'disclaimer' => $disclaimer,
            'active_run' => $activeRun?->toPublicArray(),
            'last_failed_run' => $lastFailedRun?->toPublicArray(),
            'display_only' => $displayOnly,
        ];
    }

    /**
     * Enfileira monitoramento se snapshot expirado ou ausente.
     * Abertura de tela dentro do TTL NÃO cria chamada.
     *
     * @return array{run: ?FiscalMonitoringRun, reused_snapshot: bool, enqueued: bool, reason: string, view: array<string, mixed>}
     */
    public function refresh(
        Tenant $tenant,
        Client $client,
        bool $force = false,
        ?int $actorId = null,
        bool $dispatch = true,
    ): array {
        $this->assertTenant($tenant, $client);

        $availability = $this->availability->resolve(
            FiscalControlModule::FiscalSituation,
            $tenant,
            FiscalOperationClass::Read,
        );
        if (! $availability->allowed) {
            throw new RuntimeException($availability->reason ?? 'Módulo SITFIS desabilitado.');
        }

        $driver = $this->drivers->forCapability('sitfis');
        if ($driver === SerproCapabilityDriver::Disabled) {
            throw new RuntimeException('Capacidade SITFIS desabilitada.');
        }

        $view = $this->current($tenant, $client);

        // force ou snapshot terminal/falho: não bloquear por TTL de reuso.
        $ttlBlocks = $view['is_within_ttl']
            && $view['snapshot'] !== null
            && ! $force
            && ! (bool) ($view['display_only'] ?? false)
            && $this->snapshotSituationBlocksRefresh($view['snapshot']);

        if ($ttlBlocks) {
            return [
                'run' => null,
                'reused_snapshot' => true,
                'enqueued' => false,
                'reason' => 'WITHIN_TTL',
                'view' => $this->publicView($view),
            ];
        }

        // Já há execução em andamento — não duplica
        if ($view['active_run'] !== null) {
            $status = $view['active_run']['status'] ?? null;
            if (in_array($status, ['QUEUED', 'RUNNING'], true)) {
                return [
                    'run' => null,
                    'reused_snapshot' => $view['snapshot'] !== null,
                    'enqueued' => false,
                    'reason' => 'ALREADY_RUNNING',
                    'view' => $this->publicView($view),
                ];
            }
        }

        $correlation = (string) Str::uuid();
        $run = $this->runs->enqueueManual(
            tenant: $tenant,
            client: $client,
            systemCode: $this->systemCode(),
            serviceCode: $this->serviceCode(),
            operationCode: $this->operationCode(),
            actorId: $actorId,
            correlationId: $correlation,
            dispatch: $dispatch,
        );
        $run->forceFill([
            'operation_key' => 'sitfis.emitir_relatorio',
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            // Ainda sem parse/evidência — não rotular VERIFIED no enqueue.
            'verification_state' => FiscalVerificationState::Unverified,
        ])->save();

        $this->ensureSitfisSchedule($tenant, $client, $actorId);

        $view = $this->current($tenant, $client);

        return [
            'run' => $run,
            'reused_snapshot' => false,
            'enqueued' => true,
            'reason' => 'TTL_EXPIRED_OR_MISSING',
            'view' => $this->publicView($view),
        ];
    }

    /**
     * TTL de reuso só para situações “úteis”. ERROR/BLOCKED/UNKNOWN sempre permitem enqueue.
     */
    private function snapshotSituationBlocksRefresh(FiscalSnapshot $snapshot): bool
    {
        $raw = $snapshot->situation instanceof FiscalSituation
            ? $snapshot->situation
            : FiscalSituation::tryFrom(strtoupper((string) $snapshot->situation));

        if ($raw === null) {
            return false;
        }

        return in_array($raw, [
            FiscalSituation::UpToDate,
            FiscalSituation::Pending,
            FiscalSituation::Attention,
            FiscalSituation::Processing,
        ], true);
    }

    private function ensureSitfisSchedule(Tenant $tenant, Client $client, ?int $actorId): void
    {
        try {
            $category = FiscalCategory::query()
                ->where('is_active', true)
                ->where(function ($q): void {
                    $q->where('service_code', 'SITFIS')
                        ->orWhere('code', 'SITFIS')
                        ->orWhere('module_key', 'sitfis');
                })
                ->orderBy('id')
                ->first();

            if ($category === null) {
                return;
            }

            $this->categories->associate($tenant, $client, $category, $actorId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<string, mixed>  $view
     * @return array<string, mixed>
     */
    public function publicView(array $view): array
    {
        /** @var ?FiscalSnapshot $snapshot */
        $snapshot = $view['snapshot'];

        $provenance = null;
        $verification = null;
        if ($snapshot !== null) {
            $provenance = $snapshot->source_provenance instanceof \BackedEnum
                ? $snapshot->source_provenance->value
                : $snapshot->source_provenance;
            $verification = $snapshot->verification_state instanceof \BackedEnum
                ? $snapshot->verification_state->value
                : $snapshot->verification_state;
        }

        $nextRefreshAt = $view['expires_at'] ?? null;
        $displayOnly = (bool) ($view['display_only'] ?? false);
        $canRefresh = ! $view['is_within_ttl'] || $view['snapshot'] === null || $displayOnly;
        $blockReason = null;
        if ($view['active_run'] !== null) {
            $blockReason = 'RUN_IN_PROGRESS';
            $canRefresh = false;
        } elseif ($view['is_within_ttl'] && $view['snapshot'] !== null && ! $displayOnly) {
            $blockReason = 'WITHIN_TTL';
        }

        $snapshotPublic = $snapshot?->toPublicArray();
        $situation = is_array($snapshotPublic) ? ($snapshotPublic['situation'] ?? null) : null;
        $coverage = is_array($snapshotPublic) ? ($snapshotPublic['coverage'] ?? null) : null;
        $protocol = null;
        if (is_array($snapshotPublic)) {
            $normalized = $snapshotPublic['normalized'] ?? [];
            if (is_array($normalized)) {
                $protocol = $normalized['protocol'] ?? $normalized['protocol_number'] ?? null;
            }
        }
        $lastFailed = $view['last_failed_run'] ?? null;
        if ($situation === null && is_array($lastFailed)) {
            $situation = $lastFailed['situation'] ?? null;
        }

        $evidenceArtifactId = $snapshot?->evidence_artifact_id !== null
            ? (int) $snapshot->evidence_artifact_id
            : null;
        $evidenceDownload = $evidenceArtifactId !== null
            ? '/api/v1/fiscal/evidence/'.$evidenceArtifactId.'/download'
            : null;

        return [
            'snapshot' => $snapshotPublic,
            'situation' => $situation,
            'protocol' => $protocol,
            'coverage' => $coverage,
            'evidence_artifact_id' => $evidenceArtifactId,
            'age_seconds' => $view['age_seconds'],
            'observed_at' => $view['observed_at'],
            'expires_at' => $view['expires_at'],
            'next_refresh_at' => $nextRefreshAt,
            'ttl_seconds' => $view['ttl_seconds'],
            'is_within_ttl' => $view['is_within_ttl'],
            'can_refresh' => $canRefresh,
            'block_reason' => $blockReason,
            'source_provenance' => $provenance,
            'verification_state' => $verification,
            'is_negative_certificate' => false, // hard rule: API nunca afirma certidão
            'disclaimer' => $view['disclaimer'] ?? 'Ausência de pendência reconhecida não equivale a certidão negativa.',
            'active_run' => $view['active_run'],
            'last_failed_run' => $lastFailed,
            'display_only' => $displayOnly,
            'error_code' => is_array($lastFailed) ? ($lastFailed['error_code'] ?? null) : null,
            'error_message' => is_array($lastFailed) ? ($lastFailed['error_message'] ?? null) : null,
            'links' => [
                'evidence_download' => $evidenceDownload,
            ],
            'cache_key_hint' => FiscalIdempotency::cacheKey(
                (int) ($snapshot?->tenant_id ?? 0),
                'sitfis',
                'snap',
                (string) ($snapshot?->client_id ?? 0),
            ),
        ];
    }

    private function assertTenant(Tenant $tenant, Client $client): void
    {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }
    }

    private function ttlSeconds(): int
    {
        return max(60, (int) config('fiscal_monitoring.sitfis.snapshot_ttl_seconds', 86400));
    }

    private function systemCode(): string
    {
        return (string) config('fiscal_monitoring.sitfis.system_code', 'INTEGRA_SITFIS');
    }

    private function serviceCode(): string
    {
        return (string) config('fiscal_monitoring.sitfis.service_code', 'SITFIS');
    }

    private function operationCode(): string
    {
        return (string) config('fiscal_monitoring.sitfis.operation_code', 'MONITOR');
    }
}

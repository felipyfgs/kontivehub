<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\SvrsPortalEgressGovernor;
use App\Enums\OutboundRetrievalOrigin;
use App\Enums\SvrsNfceRecoveryStatus;
use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sefaz\EnqueueSvrsNfceRecoveryRequest;
use App\Http\Requests\Sefaz\ExtendSvrsNfceEgressCooldownRequest;
use App\Http\Requests\Sefaz\ListSvrsNfceRecoveryRequest;
use App\Http\Requests\Sefaz\ResetSvrsNfceBreakerRequest;
use App\Http\Requests\Sefaz\SelectSvrsNfceEgressCanaryRequest;
use App\Http\Requests\Sefaz\ToggleSvrsNfceKillSwitchRequest;
use App\Models\Client;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundNumberState;
use App\Models\OutboundRetrievalAttempt;
use App\Models\OutboundRetrievalRequest;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Outbound\OutboundXmlRecoveryOrchestrator;
use App\Services\Outbound\SvrsNfceCircuitBreaker;
use App\Services\Outbound\SvrsNfceConfig;
use App\Services\Outbound\SvrsNfceKillSwitchService;
use App\Services\Outbound\SvrsNfe55Config;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API same-origin do canal SVRS NFC-e / saúde de coorte — DTOs sanitizados; tenant_id do servidor.
 */
class SvrsNfceRecoveryController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly SvrsNfceConfig $config,
        private readonly SvrsNfe55Config $nfe55Config,
        private readonly SvrsNfceKillSwitchService $killSwitch,
        private readonly SvrsNfceCircuitBreaker $breaker,
        private readonly SvrsPortalEgressGovernor $egressGovernor,
        private readonly OutboundXmlRecoveryOrchestrator $orchestrator,
    ) {}

    public function channelSummary(): JsonResponse
    {
        $this->authorizeView();
        $tenantId = $this->currentTenant->id();

        $backlog = OutboundRetrievalRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereIn('recovery_status', [
                SvrsNfceRecoveryStatus::Eligible,
                SvrsNfceRecoveryStatus::Queued,
                SvrsNfceRecoveryStatus::Running,
                SvrsNfceRecoveryStatus::RetryScheduled,
            ])
            ->count();

        $oldest = OutboundRetrievalRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereIn('recovery_status', [
                SvrsNfceRecoveryStatus::Eligible,
                SvrsNfceRecoveryStatus::Queued,
                SvrsNfceRecoveryStatus::RetryScheduled,
            ])
            ->orderBy('created_at')
            ->value('created_at');

        $egress = $this->egressGovernor->cohortHealth();

        return response()->json([
            'data' => [
                'retrieval_enabled' => $this->config->retrievalEnabled(),
                'auto_queue_enabled' => $this->config->autoQueueEnabled(),
                'nfe55_retrieval_enabled' => $this->nfe55Config->retrievalEnabled(),
                'nfe55_auto_queue_enabled' => $this->nfe55Config->autoQueueEnabled(),
                'pilot_allowlist_only' => $this->config->pilotAllowlistOnly(),
                'kill_switch' => $this->killSwitch->status(),
                'breaker_global' => $this->breaker->globalStatus(),
                'egress_cohort' => [
                    'cohort_id' => $egress['cohort_id'],
                    'state' => $egress['state'],
                    'cause' => $egress['cause'],
                    'tier' => $egress['tier'],
                    'next_probe_at' => $egress['next_probe_at'],
                    'exchanges_hour_remaining' => $egress['exchanges_hour_remaining'],
                    'exchanges_day_remaining' => $egress['exchanges_day_remaining'],
                    'inflight' => $egress['inflight'],
                    // budgets preventivos — não limites oficiais NFESSL
                    'budgets_are_preventive' => true,
                ],
                'backlog' => $backlog,
                'oldest_pending_at' => $oldest?->toIso8601String(),
                'parser_version' => $this->config->parserVersion(),
                'host' => $this->config->host(),
                // sem cookie, PFX, URL arbitraria, chave completa, CNPJ
            ],
        ]);
    }

    /**
     * Saúde da coorte de egress (sem dados privados de outros escritórios).
     */
    public function egressCohortHealth(): JsonResponse
    {
        $this->authorizeView();
        $egress = $this->egressGovernor->cohortHealth();

        return response()->json([
            'data' => [
                'cohort_id' => $egress['cohort_id'],
                'state' => $egress['state'],
                'cause' => $egress['cause'],
                'tier' => $egress['tier'],
                'opened_at' => $egress['opened_at'],
                'next_probe_at' => $egress['next_probe_at'],
                'canary_key_mask' => $egress['canary_key_mask'],
                'exchanges_hour' => $egress['exchanges_hour'],
                'exchanges_day' => $egress['exchanges_day'],
                'exchanges_hour_remaining' => $egress['exchanges_hour_remaining'],
                'exchanges_day_remaining' => $egress['exchanges_day_remaining'],
                'inflight' => $egress['inflight'],
                'budgets_are_preventive' => true,
                'note' => 'Limites internos preventivos; não são limites oficiais publicados do NFESSL/NFCESSL.',
            ],
        ]);
    }

    public function profileSummary(OutboundCaptureProfile $profile): JsonResponse
    {
        $this->authorizeView();
        $this->assertProfileTenant($profile);

        $pending = OutboundRetrievalRequest::query()
            ->where('outbound_capture_profile_id', $profile->id)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereIn('recovery_status', [
                SvrsNfceRecoveryStatus::Eligible,
                SvrsNfceRecoveryStatus::Queued,
                SvrsNfceRecoveryStatus::Running,
                SvrsNfceRecoveryStatus::RetryScheduled,
                SvrsNfceRecoveryStatus::NotAvailableVisible,
                SvrsNfceRecoveryStatus::Blocked,
            ])
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (OutboundRetrievalRequest $r) => $r->toPublicArray());

        $lastCaptured = OutboundRetrievalRequest::query()
            ->where('outbound_capture_profile_id', $profile->id)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->where('recovery_status', SvrsNfceRecoveryStatus::Captured)
            ->orderByDesc('ingested_at')
            ->first();

        return response()->json([
            'data' => [
                'profile_id' => $profile->id,
                'model' => $profile->model->value ?? $profile->model,
                'eligible_model' => ($profile->model->value ?? $profile->model) === '65',
                'allowlisted' => (bool) $profile->allowlisted,
                'flags' => [
                    'retrieval_enabled' => $this->config->retrievalEnabled(),
                    'auto_queue_enabled' => $this->config->autoQueueEnabled(),
                    'pilot_allowlist_only' => $this->config->pilotAllowlistOnly(),
                    'kill_switch' => $this->killSwitch->isActive(),
                ],
                'breaker_root' => $this->breaker->rootStatus((int) $profile->client_id),
                'breaker_global' => $this->breaker->globalStatus(),
                'recent' => $pending,
                'last_captured' => $lastCaptured?->toPublicArray(),
            ],
        ]);
    }

    public function index(ListSvrsNfceRecoveryRequest $request): JsonResponse
    {
        $this->authorizeView();
        $tenantId = $this->currentTenant->id();

        $q = OutboundRetrievalRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->orderByDesc('id');

        if ($request->status() !== null) {
            $q->where('recovery_status', $request->status());
        }
        if ($request->profileId() !== null) {
            $q->where('outbound_capture_profile_id', $request->profileId());
        }
        // Escopo por cliente do escritório (nunca confiar tenant_id do payload)
        if ($request->clientId() !== null) {
            $profileIds = OutboundCaptureProfile::query()
                ->where('tenant_id', $tenantId)
                ->where('client_id', $request->clientId())
                ->pluck('id');
            $q->whereIn('outbound_capture_profile_id', $profileIds);
        }

        $page = $q->paginate($request->perPage());

        return response()->json([
            'data' => collect($page->items())->map(fn (OutboundRetrievalRequest $r) => $r->toPublicArray()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function attempts(OutboundRetrievalRequest $recovery): JsonResponse
    {
        $this->authorizeView();
        $this->assertRecoveryTenant($recovery);

        $rows = OutboundRetrievalAttempt::query()
            ->where('outbound_retrieval_request_id', $recovery->id)
            ->orderBy('attempt_number')
            ->get()
            ->map(fn (OutboundRetrievalAttempt $a) => $a->toPublicArray());

        return response()->json(['data' => $rows]);
    }

    public function enqueue(EnqueueSvrsNfceRecoveryRequest $request): JsonResponse
    {
        $this->authorizeOperate();

        $number = OutboundNumberState::query()->find($request->numberStateId());
        if ($number === null) {
            abort(404);
        }

        $profile = OutboundCaptureProfile::query()->find($number->outbound_capture_profile_id);
        if ($profile === null) {
            abort(404);
        }

        $recovery = $this->orchestrator->ensureRecovery(
            $number,
            $profile,
            queue: true,
            userId: (int) $request->user()->id,
            triggeredBy: 'operator',
        );

        if ($recovery === null) {
            return response()->json([
                'message' => 'Número não elegível para recuperação SVRS.',
            ], 422);
        }

        return response()->json(['data' => $recovery->toPublicArray()]);
    }

    public function retry(OutboundRetrievalRequest $recovery): JsonResponse
    {
        $this->authorizeOperate();
        $this->assertRecoveryTenant($recovery);

        if ($this->breaker->globalStatus()['state'] === 'open') {
            return response()->json([
                'message' => 'Circuit breaker global aberto — use fallback assistido.',
            ], 422);
        }

        if ($recovery->recovery_status === SvrsNfceRecoveryStatus::Blocked
            && $recovery->failure_reason?->opensGlobalBreaker()) {
            return response()->json([
                'message' => 'Recovery bloqueado por contrato/auth — fallback assistido.',
            ], 422);
        }

        // Reabrir para retry se não capturado
        if ($recovery->recovery_status?->isTerminal()
            && $recovery->recovery_status !== SvrsNfceRecoveryStatus::NotAvailableVisible) {
            return response()->json(['message' => 'Recovery em estado terminal.'], 422);
        }

        // Zera contador para novo ciclo de backoff (retry manual)
        $recovery->forceFill([
            'recovery_status' => SvrsNfceRecoveryStatus::Eligible,
            'attempt_count' => 0,
            'failure_reason' => null,
            'last_error' => null,
            'next_attempt_at' => null,
        ])->save();

        $this->orchestrator->enqueue($recovery->fresh(), (int) request()->user()->id, 'operator_retry');

        return response()->json(['data' => $recovery->fresh()->toPublicArray()]);
    }

    public function killSwitchStatus(): JsonResponse
    {
        $this->authorizeView();

        return response()->json(['data' => $this->killSwitch->status()]);
    }

    public function killSwitch(ToggleSvrsNfceKillSwitchRequest $request): JsonResponse
    {
        $this->authorizeAdminWithRecentPassword();

        if ($request->active()) {
            $this->killSwitch->activate($request->reason(), (int) $request->user()->id, $this->currentTenant->id());
        } else {
            $this->killSwitch->deactivate($request->reason(), (int) $request->user()->id, $this->currentTenant->id());
        }

        return response()->json(['data' => $this->killSwitch->status()]);
    }

    public function breakerStatus(): JsonResponse
    {
        $this->authorizeView();

        return response()->json([
            'data' => [
                'global' => $this->breaker->globalStatus(),
            ],
        ]);
    }

    public function breakerReset(ResetSvrsNfceBreakerRequest $request): JsonResponse
    {
        $this->authorizeAdminWithRecentPassword();

        if ($request->scope() === 'global') {
            $this->breaker->resetGlobal($request->reason(), (int) $request->user()->id, $this->currentTenant->id());
        } else {
            $clientId = (int) ($request->clientId() ?? 0);
            if ($clientId < 1) {
                return response()->json(['message' => 'client_id obrigatório para scope root.'], 422);
            }
            // Tenancy: client deve pertencer ao escritório da sessão
            $client = Client::query()
                ->where('id', $clientId)
                ->where('tenant_id', $this->currentTenant->id())
                ->first();
            if ($client === null) {
                abort(404);
            }
            $this->breaker->resetRoot($clientId, $request->reason(), (int) $request->user()->id, $this->currentTenant->id());
        }

        return response()->json([
            'data' => [
                'global' => $this->breaker->globalStatus(),
            ],
        ]);
    }

    /**
     * Estende cooldown da coorte (nunca antecipa next_probe_at).
     */
    public function extendEgressCooldown(ExtendSvrsNfceEgressCooldownRequest $request): JsonResponse
    {
        $this->authorizeAdminWithRecentPassword();

        $this->egressGovernor->extendCooldown(
            $request->additionalSeconds(),
            (int) $request->user()->id,
            $this->currentTenant->id(),
        );

        return response()->json([
            'data' => $this->egressGovernor->cohortHealth(),
            'meta' => ['reason' => mb_substr($request->reason(), 0, 200)],
        ]);
    }

    /**
     * Seleciona canário elegível após next_probe_at (half-open). Não antecipa prova.
     */
    public function selectEgressCanary(SelectSvrsNfceEgressCanaryRequest $request): JsonResponse
    {
        $this->authorizeAdminWithRecentPassword();

        $number = OutboundNumberState::query()->find($request->numberStateId());
        if ($number === null || (int) $number->tenant_id !== (int) $this->currentTenant->id()) {
            abort(404);
        }

        $key = strtoupper(trim((string) ($number->discovered_access_key ?: $number->candidate_access_key)));
        if (strlen($key) !== 44) {
            return response()->json(['message' => 'Número sem chave válida para canário.'], 422);
        }

        $health = $this->egressGovernor->cohortHealth();
        if ($health['state'] === 'open') {
            return response()->json([
                'message' => 'Cooldown ativo — não é possível canário antes de next_probe_at.',
                'data' => $health,
            ], 422);
        }

        $mask = substr($key, 0, 6).'…'.substr($key, -4);
        $result = $this->egressGovernor->selectCanary(
            $mask,
            hash('sha256', $key),
            (int) $request->user()->id,
            $this->currentTenant->id(),
        );

        if (! $result['ok']) {
            return response()->json([
                'message' => 'Canário recusado: '.$result['reason'],
                'data' => $this->egressGovernor->cohortHealth(),
            ], 422);
        }

        return response()->json([
            'data' => $this->egressGovernor->cohortHealth(),
            'meta' => ['reason' => mb_substr($request->reason(), 0, 200), 'key_mask' => $mask],
        ]);
    }

    /**
     * Recusa explícita de elevação de limites / bypass de cooldown / URL arbitrária.
     */
    public function refuseBudgetElevation(Request $request): JsonResponse
    {
        $this->authorizeAdminWithRecentPassword();

        return response()->json([
            'message' => 'Elevação de orçamento, antecipação de next_probe_at, URL/host/proxy/cookie arbitrários não são permitidos via API.',
            'data' => [
                'allowed' => false,
                'budgets_are_preventive' => true,
                'cohort' => $this->egressGovernor->cohortHealth(),
            ],
        ], 422);
    }

    private function authorizeView(): void
    {
        $this->currentTenant->tenant(); // ensures resolved
        abort_unless(auth()->check(), 401);
    }

    private function authorizeOperate(): void
    {
        $this->authorizeView();
        $role = $this->currentTenant->role();
        abort_unless(in_array($role, [TenantRole::TenantAdmin, TenantRole::TenantUser], true), 403);
    }

    private function authorizeAdminWithRecentPassword(): void
    {
        $this->authorizeView();
        abort_unless($this->currentTenant->role() === TenantRole::TenantAdmin, 403);
        $user = auth()->user();
        abort_unless($user instanceof User, 403);
        abort_unless(app(RecentPasswordConfirmationGate::class)->isRecentlyConfirmed($user), 403);
    }

    private function assertProfileTenant(OutboundCaptureProfile $profile): void
    {
        if ((int) $profile->tenant_id !== (int) $this->currentTenant->id()) {
            abort(404);
        }
    }

    private function assertRecoveryTenant(OutboundRetrievalRequest $recovery): void
    {
        if ((int) $recovery->tenant_id !== (int) $this->currentTenant->id()) {
            abort(404);
        }
        if ($recovery->origin !== OutboundRetrievalOrigin::SvrsPortalByKey) {
            abort(404);
        }
    }
}

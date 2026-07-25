<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\SvrsPortalEgressGovernor;
use App\Enums\OfficeRole;
use App\Enums\OutboundRetrievalOrigin;
use App\Enums\SvrsNfceRecoveryStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\MaOutboundRetrievalRequest;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundNumberState;
use App\Models\OutboundXmlRecoveryAttempt;
use App\Services\Outbound\OutboundXmlRecoveryOrchestrator;
use App\Services\Outbound\SvrsNfceCircuitBreaker;
use App\Services\Outbound\SvrsNfceConfig;
use App\Services\Outbound\SvrsNfceKillSwitchService;
use App\Services\Outbound\SvrsNfe55Config;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API same-origin do canal SVRS NFC-e / saúde de coorte — DTOs sanitizados; office_id do servidor.
 */
class SvrsNfceRecoveryController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
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
        $officeId = $this->currentOffice->id();

        $backlog = MaOutboundRetrievalRequest::query()
            ->where('office_id', $officeId)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereIn('recovery_status', [
                SvrsNfceRecoveryStatus::Eligible,
                SvrsNfceRecoveryStatus::Queued,
                SvrsNfceRecoveryStatus::Running,
                SvrsNfceRecoveryStatus::RetryScheduled,
            ])
            ->count();

        $oldest = MaOutboundRetrievalRequest::query()
            ->where('office_id', $officeId)
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
        $this->assertProfileOffice($profile);

        $pending = MaOutboundRetrievalRequest::query()
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
            ->map(fn (MaOutboundRetrievalRequest $r) => $r->toPublicArray());

        $lastCaptured = MaOutboundRetrievalRequest::query()
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

    public function index(Request $request): JsonResponse
    {
        $this->authorizeView();
        $officeId = $this->currentOffice->id();

        $q = MaOutboundRetrievalRequest::query()
            ->where('office_id', $officeId)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $q->where('recovery_status', (string) $request->string('status'));
        }
        if ($request->filled('profile_id')) {
            $q->where('outbound_capture_profile_id', (int) $request->input('profile_id'));
        }
        // Escopo por cliente do escritório (nunca confiar office_id do payload)
        if ($request->filled('client_id')) {
            $clientId = (int) $request->input('client_id');
            $profileIds = OutboundCaptureProfile::query()
                ->where('office_id', $officeId)
                ->where('client_id', $clientId)
                ->pluck('id');
            $q->whereIn('outbound_capture_profile_id', $profileIds);
        }

        $page = $q->paginate(min(100, max(1, (int) $request->input('per_page', 20))));

        return response()->json([
            'data' => collect($page->items())->map(fn (MaOutboundRetrievalRequest $r) => $r->toPublicArray()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function attempts(MaOutboundRetrievalRequest $recovery): JsonResponse
    {
        $this->authorizeView();
        $this->assertRecoveryOffice($recovery);

        $rows = OutboundXmlRecoveryAttempt::query()
            ->where('ma_outbound_retrieval_request_id', $recovery->id)
            ->orderBy('attempt_number')
            ->get()
            ->map(fn (OutboundXmlRecoveryAttempt $a) => $a->toPublicArray());

        return response()->json(['data' => $rows]);
    }

    public function enqueue(Request $request): JsonResponse
    {
        $this->authorizeOperate();

        // Ignorar office_id / url / host / headers / cookie / credential do cliente
        $request->request->remove('office_id');
        $request->request->remove('url');
        $request->request->remove('host');
        $request->request->remove('headers');
        $request->request->remove('cookie');
        $request->request->remove('credential_id');
        $request->request->remove('vault_object_id');

        $validated = $request->validate([
            'number_state_id' => ['required', 'integer'],
        ]);

        $number = OutboundNumberState::query()->find((int) $validated['number_state_id']);
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

    public function retry(MaOutboundRetrievalRequest $recovery): JsonResponse
    {
        $this->authorizeOperate();
        $this->assertRecoveryOffice($recovery);

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

    public function killSwitch(Request $request): JsonResponse
    {
        $this->authorizeAdmin2fa();
        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($data['active']) {
            $this->killSwitch->activate($data['reason'], (int) $request->user()->id, $this->currentOffice->id());
        } else {
            $this->killSwitch->deactivate($data['reason'], (int) $request->user()->id, $this->currentOffice->id());
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

    public function breakerReset(Request $request): JsonResponse
    {
        $this->authorizeAdmin2fa();
        $data = $request->validate([
            'scope' => ['required', 'in:global,root'],
            'client_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        if ($data['scope'] === 'global') {
            $this->breaker->resetGlobal($data['reason'], (int) $request->user()->id, $this->currentOffice->id());
        } else {
            $clientId = (int) ($data['client_id'] ?? 0);
            if ($clientId < 1) {
                return response()->json(['message' => 'client_id obrigatório para scope root.'], 422);
            }
            // Tenancy: client deve pertencer ao escritório da sessão
            $client = Client::query()
                ->where('id', $clientId)
                ->where('office_id', $this->currentOffice->id())
                ->first();
            if ($client === null) {
                abort(404);
            }
            $this->breaker->resetRoot($clientId, $data['reason'], (int) $request->user()->id, $this->currentOffice->id());
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
    public function extendEgressCooldown(Request $request): JsonResponse
    {
        $this->authorizeAdmin2fa();
        // Recusar campos de elevação de orçamento / URL / proxy
        foreach (['min_interval', 'max_exchanges', 'url', 'host', 'headers', 'cookie', 'proxy', 'next_probe_at'] as $forbidden) {
            $request->request->remove($forbidden);
        }

        $data = $request->validate([
            'additional_seconds' => ['required', 'integer', 'min:60', 'max:604800'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->egressGovernor->extendCooldown(
            (int) $data['additional_seconds'],
            (int) $request->user()->id,
            $this->currentOffice->id(),
        );

        return response()->json([
            'data' => $this->egressGovernor->cohortHealth(),
            'meta' => ['reason' => mb_substr($data['reason'], 0, 200)],
        ]);
    }

    /**
     * Seleciona canário elegível após next_probe_at (half-open). Não antecipa prova.
     */
    public function selectEgressCanary(Request $request): JsonResponse
    {
        $this->authorizeAdmin2fa();
        foreach (['url', 'host', 'headers', 'cookie', 'proxy', 'next_probe_at', 'min_interval'] as $forbidden) {
            $request->request->remove($forbidden);
        }

        $data = $request->validate([
            'number_state_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $number = OutboundNumberState::query()->find((int) $data['number_state_id']);
        if ($number === null || (int) $number->office_id !== (int) $this->currentOffice->id()) {
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
            $this->currentOffice->id(),
        );

        if (! $result['ok']) {
            return response()->json([
                'message' => 'Canário recusado: '.$result['reason'],
                'data' => $this->egressGovernor->cohortHealth(),
            ], 422);
        }

        return response()->json([
            'data' => $this->egressGovernor->cohortHealth(),
            'meta' => ['reason' => mb_substr($data['reason'], 0, 200), 'key_mask' => $mask],
        ]);
    }

    /**
     * Recusa explícita de elevação de limites / bypass de cooldown / URL arbitrária.
     */
    public function refuseBudgetElevation(Request $request): JsonResponse
    {
        $this->authorizeAdmin2fa();

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
        $this->currentOffice->office(); // ensures resolved
        abort_unless(auth()->check(), 401);
    }

    private function authorizeOperate(): void
    {
        $this->authorizeView();
        $role = $this->currentOffice->role();
        abort_unless(in_array($role, [OfficeRole::Admin, OfficeRole::Operator], true), 403);
    }

    private function authorizeAdmin2fa(): void
    {
        $this->authorizeView();
        abort_unless($this->currentOffice->role() === OfficeRole::Admin, 403);
        $user = auth()->user();
        abort_unless($user && $user->two_factor_confirmed_at, 403);
    }

    private function assertProfileOffice(OutboundCaptureProfile $profile): void
    {
        if ((int) $profile->office_id !== (int) $this->currentOffice->id()) {
            abort(404);
        }
    }

    private function assertRecoveryOffice(MaOutboundRetrievalRequest $recovery): void
    {
        if ((int) $recovery->office_id !== (int) $this->currentOffice->id()) {
            abort(404);
        }
        if ($recovery->origin !== OutboundRetrievalOrigin::SvrsPortalByKey) {
            abort(404);
        }
    }
}

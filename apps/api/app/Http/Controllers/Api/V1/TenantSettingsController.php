<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\FiscalControlModule;
use App\Enums\FiscalOperationClass;
use App\Enums\FiscalProfile;
use App\Enums\TenantSerproOnboardingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\GrantTenantTechnicalConsentRequest;
use App\Http\Requests\Tenant\RemoveTenantCertificateRequest;
use App\Http\Requests\Tenant\UpdateTenantInstitutionalProfileRequest;
use App\Models\Client;
use App\Models\ClientProcuracaoSync;
use App\Models\FiscalMonitoringRun;
use App\Models\TenantCredential;
use App\Models\TenantCredentialPurposeLink;
use App\Models\TenantMonitorSchedulePolicy;
use App\Policies\TenantSettingsPolicy;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\TenantCredentialService;
use App\Services\Certificates\TenantInstitutionalProfileService;
use App\Services\Certificates\TenantTechnicalConsentService;
use App\Services\Fiscal\Availability\FiscalModuleAvailabilityService;
use App\Services\Integra\SerproTenantActionableStatusService;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Services\Integra\TenantSerproOnboardingService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Superfície tenant-scoped de /settings: perfil, consentimento e certificado.
 * Sem download/recuperação de PFX/senha; tenant_id só via CurrentTenant.
 */
class TenantSettingsController extends Controller
{
    /**
     * Monitores com agenda mensal (dia 1–28) — chaves estáveis alinhadas ao painel.
     *
     * @var array<string, string>
     */
    private const MONITOR_SCHEDULE_LABELS = [
        'sitfis' => 'Situação fiscal',
        'simples_mei' => 'Simples / MEI',
        'dctfweb' => 'DCTFWeb / MIT',
        'installments' => 'Parcelamentos',
        'mailbox' => 'Caixa postal',
        'declarations' => 'Declarações',
        'guides' => 'Guias',
        'fgts' => 'FGTS (parcial)',
    ];

    /**
     * Labels acionáveis (tenant-facing) para códigos de onboarding.
     *
     * @var array<string, string>
     */
    private const ACTIONABLE_LABELS = [
        'COMPLETE_PROFILE' => 'Completar perfil',
        'ACCEPT_CONSENT' => 'Aceitar consentimento',
        'UPLOAD_CERTIFICATE' => 'Enviar certificado',
        'UPLOAD_TERMO' => 'Regularizar Termo de autorização',
        'SIGNATURE_REQUIRED' => 'Assinatura do Termo pendente',
        'REONBOARD_REQUIRED' => 'Reativar integrações',
        'PLATFORM_UNAVAILABLE' => 'Aguardar suporte da plataforma',
        'MISSING_PROFILE' => 'Completar perfil',
        'MISSING_CONSENT' => 'Aceitar consentimento',
        'MISSING_CERTIFICATE' => 'Enviar certificado',
    ];

    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TenantInstitutionalProfileService $profiles,
        private readonly TenantCredentialService $credentials,
        private readonly TenantTechnicalConsentService $consents,
        private readonly SerproTenantActionableStatusService $actionableStatus,
        private readonly FiscalModuleAvailabilityService $moduleAvailability,
        private readonly TenantSerproOnboardingService $onboarding,
        private readonly TenantSerproAuthorizationService $authorizations,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        $this->authorizeView();

        $profile = $this->profiles->forCurrentTenant();
        $credential = $this->credentials->activeForCurrentTenant();
        // Atualiza flags de alerta (dedupe) antes de expor no painel.
        $this->credentials->refreshExpiryAlerts();
        $credential = $credential?->fresh() ?? $this->credentials->activeForCurrentTenant();

        $links = [];
        if ($credential !== null) {
            $links = TenantCredentialPurposeLink::query()
                ->where('tenant_credential_id', $credential->id)
                ->where('status', 'ACTIVE')
                ->orderBy('purpose')
                ->get()
                ->map(fn (TenantCredentialPurposeLink $link) => $link->toPublicArray())
                ->values()
                ->all();
        }

        return response()->json([
            'data' => [
                'profile' => $profile->toPublicArray(),
                'consent' => $this->consents->currentStatus(),
                'certificate' => $credential?->toPublicArray(),
                'purpose_links' => $links,
                'alerts' => $this->credentials->panelExpiryAlerts($credential),
            ],
        ]);
    }

    public function updateProfile(UpdateTenantInstitutionalProfileRequest $request): JsonResponse
    {
        $this->authorizeManage();

        try {
            $result = $this->profiles->update(
                $request->validated(),
                $request->user()?->id,
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->audit->record('tenant.institutional_profile.update', 'FAILED', null, [
                'message' => $e->getMessage(),
            ], $request->user()?->id);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'profile' => $result['profile']->toPublicArray(),
                'cnpj_changed' => $result['cnpj_changed'],
                'invalidated' => $result['invalidated'],
            ],
        ]);
    }

    public function showConsent(): JsonResponse
    {
        $this->authorizeView();

        return response()->json([
            'data' => $this->consents->currentStatus(),
        ]);
    }

    public function grantConsent(GrantTenantTechnicalConsentRequest $request): JsonResponse
    {
        $this->authorizeManage();

        $data = $request->validated();

        try {
            $consent = $this->consents->grant(
                accepted: true,
                actorUserId: $request->user()?->id,
                versionCode: $data['version_code'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => $consent->toPublicArray(),
        ], 201);
    }

    public function revokeConsent(Request $request): JsonResponse
    {
        $this->authorizeManage();
        $request->request->remove('tenant_id');

        $consent = $this->consents->revoke($request->user()?->id);
        if ($consent === null) {
            return response()->json([
                'message' => 'Não há consentimento ativo para revogar.',
            ], 422);
        }

        return response()->json([
            'data' => $consent->toPublicArray(),
        ]);
    }

    public function showCertificate(): JsonResponse
    {
        $this->authorizeView();

        $this->credentials->refreshExpiryAlerts();
        $credential = $this->credentials->activeForCurrentTenant();

        return response()->json([
            'data' => [
                'certificate' => $credential?->toPublicArray(),
                'alerts' => $this->credentials->panelExpiryAlerts($credential),
            ],
        ]);
    }

    public function storeCertificate(Request $request): JsonResponse
    {
        $this->authorizeManage();
        $request->request->remove('tenant_id');

        $data = $request->validate([
            'pfx' => ['required', 'file', 'max:5120'],
            'password' => ['required', 'string'],
            'consent_accepted' => ['required', 'accepted'],
            'tenant_id' => ['prohibited'],
        ]);

        try {
            $binary = file_get_contents($data['pfx']->getRealPath());
            if ($binary === false) {
                throw new RuntimeException('Falha ao ler arquivo PFX.');
            }

            // Consentimento antes da ativação: se grant falhar, o certificado não fica ativo sem registro.
            if (($this->consents->currentStatus()['requires_consent'] ?? true) === true) {
                $this->consents->grant(true, $request->user()?->id);
            }
            $credential = $this->credentials->activate(
                $binary,
                $data['password'],
                $request->user()?->id,
            );
            unset($binary, $data['password']);
        } catch (RuntimeException $e) {
            $this->audit->record('tenant_credential.activate', 'FAILED', null, [
                'message' => $e->getMessage() ?: 'Falha ao ativar certificado.',
            ], $request->user()?->id);

            return response()->json([
                'message' => $e->getMessage() ?: 'Falha ao ativar certificado.',
            ], 422);
        } catch (Throwable $e) {
            report($e);
            $this->audit->record('tenant_credential.activate', 'FAILED', null, [
                'message' => 'Falha ao ativar certificado.',
            ], $request->user()?->id);

            return response()->json([
                'message' => 'Falha ao ativar certificado.',
            ], 422);
        }

        $this->audit->record('tenant_credential.activate', 'SUCCESS', $credential, [
            'fingerprint_sha256' => $credential->fingerprint_sha256,
            'holder_cnpj' => $credential->holder_cnpj,
            'valid_to' => $credential->valid_to?->toIso8601String(),
            'credential_type' => 'CERTIFICATE',
        ], $request->user()?->id);

        $tenant = $this->currentTenant->tenant();

        return response()->json([
            'data' => array_merge($this->certificatePayload($credential), [
                'onboarding' => $this->actionableStatus->forTenant($tenant)['onboarding'] ?? null,
            ]),
        ], 202);
    }

    public function replaceCertificate(Request $request): JsonResponse
    {
        $this->authorizeManage();
        $request->request->remove('tenant_id');

        $data = $request->validate([
            'pfx' => ['required', 'file', 'max:5120'],
            'password' => ['required', 'string'],
            'consent_accepted' => ['required', 'accepted'],
            'tenant_id' => ['prohibited'],
        ]);

        $previous = $this->credentials->activeForCurrentTenant();
        $previousFingerprint = $previous?->fingerprint_sha256;

        try {
            $binary = file_get_contents($data['pfx']->getRealPath());
            if ($binary === false) {
                throw new RuntimeException('Falha ao ler arquivo PFX.');
            }

            // Consentimento antes da ativação: se grant falhar, o certificado não fica ativo sem registro.
            if (($this->consents->currentStatus()['requires_consent'] ?? true) === true) {
                $this->consents->grant(true, $request->user()?->id);
            }
            $credential = $this->credentials->replace(
                $binary,
                $data['password'],
                $request->user()?->id,
            );
            unset($binary, $data['password']);
        } catch (RuntimeException $e) {
            // Valida antes de ativar: a credencial anterior permanece.
            $stillActive = $this->credentials->activeForCurrentTenant();
            $this->audit->record('tenant_credential.replace', 'FAILED', $stillActive, [
                'message' => $e->getMessage() ?: 'Falha ao substituir certificado.',
                'previous_fingerprint_sha256' => $previousFingerprint,
                'previous_still_active' => $stillActive !== null
                    && $stillActive->fingerprint_sha256 === $previousFingerprint,
            ], $request->user()?->id);

            return response()->json([
                'message' => $e->getMessage() ?: 'Falha ao substituir certificado.',
                'previous_preserved' => true,
            ], 422);
        } catch (Throwable $e) {
            report($e);
            $this->audit->record('tenant_credential.replace', 'FAILED', null, [
                'message' => 'Falha ao substituir certificado.',
            ], $request->user()?->id);

            return response()->json([
                'message' => 'Falha ao substituir certificado.',
                'previous_preserved' => true,
            ], 422);
        }

        $this->audit->record('tenant_credential.replace', 'SUCCESS', $credential, [
            'fingerprint_sha256' => $credential->fingerprint_sha256,
            'previous_fingerprint_sha256' => $previousFingerprint,
            'holder_cnpj' => $credential->holder_cnpj,
            'valid_to' => $credential->valid_to?->toIso8601String(),
        ], $request->user()?->id);

        $tenant = $this->currentTenant->tenant();

        return response()->json([
            'data' => array_merge($this->certificatePayload($credential), [
                'onboarding' => $this->actionableStatus->forTenant($tenant)['onboarding'] ?? null,
            ]),
        ], 202);
    }

    public function removeCertificate(RemoveTenantCertificateRequest $request): JsonResponse
    {
        $this->authorizeManage();

        try {
            $credential = $this->credentials->remove(
                confirmed: true,
                actorUserId: $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($credential === null) {
            return response()->json([
                'message' => 'Não há certificado ativo para remover.',
            ], 422);
        }

        $this->audit->record('tenant_credential.remove', 'SUCCESS', $credential, [
            'fingerprint_sha256' => $credential->fingerprint_sha256,
            'holder_cnpj' => $credential->holder_cnpj,
        ], $request->user()?->id);

        return response()->json([
            'data' => [
                'certificate' => $credential->toPublicArray(),
                'removed' => true,
            ],
        ]);
    }

    /**
     * Regenera a integração SERPRO do escritório sem reenviar o certificado.
     * Usa o certificado e o consentimento já ativos.
     */
    public function refreshIntegration(Request $request): JsonResponse
    {
        $this->authorizeManage();
        $tenant = $this->currentTenant->tenant();
        $env = FiscalProfile::configured()->serproEnvironment();

        if ($this->credentials->activeForCurrentTenant() === null) {
            return response()->json([
                'message' => 'Envie o certificado do escritório antes de atualizar a integração.',
            ], 422);
        }

        try {
            $this->onboarding->ensureAuthorFromCertificate(
                $tenant,
                $env,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            $this->audit->record('tenant.integration.refresh', 'FAILED', null, [
                'message' => $e->getMessage(),
                'environment' => $env->value,
                'stage' => 'ensure_author',
            ], $request->user()?->id, $tenant->id);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $auth = null;
        $refreshError = null;
        try {
            $auth = $this->authorizations->refreshProcuradorToken(
                $tenant,
                $env,
                $request->user()?->id,
                force: true,
            );
        } catch (RuntimeException $e) {
            // Termo ausente etc.: ainda avalia onboarding para enfileirar assinatura/reparo.
            $refreshError = $e;
        }

        try {
            $this->onboarding->evaluateAndMaybeEnqueue(
                $tenant,
                $env,
                $request->user()?->id,
                force: true,
            );
        } catch (RuntimeException $e) {
            $this->audit->record('tenant.integration.refresh', 'FAILED', null, [
                'message' => $e->getMessage(),
                'environment' => $env->value,
                'stage' => 'onboarding',
                'refresh_error' => $refreshError?->getMessage(),
            ], $request->user()?->id, $tenant->id);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $auth ??= $this->authorizations->getOrCreate($tenant, $env);

        if ($refreshError !== null) {
            $this->audit->record('tenant.integration.refresh', 'PARTIAL', $auth, [
                'environment' => $env->value,
                'status' => $auth->status->value,
                'message' => $refreshError->getMessage(),
                'onboarding_evaluated' => true,
            ], $request->user()?->id, $tenant->id);

            return response()->json([
                'message' => $refreshError->getMessage(),
                'data' => [
                    'status' => $auth->status->value,
                    'procurador_token_expires_at' => $auth->procurador_token_expires_at?->toIso8601String(),
                    'has_procurador_token' => $auth->procurador_token_vault_object_id !== null,
                    'onboarding_evaluated' => true,
                ],
            ], 422);
        }

        $this->audit->record('tenant.integration.refresh', 'SUCCESS', $auth, [
            'environment' => $env->value,
            'status' => $auth->status->value,
        ], $request->user()?->id, $tenant->id);

        return response()->json([
            'data' => [
                'status' => $auth->status->value,
                'procurador_token_expires_at' => $auth->procurador_token_expires_at?->toIso8601String(),
                'has_procurador_token' => $auth->procurador_token_vault_object_id !== null,
            ],
        ]);
    }

    /**
     * Políticas de agenda mensal por monitor (dia 1–28) do escritório corrente.
     */
    public function listMonitorSchedules(): JsonResponse
    {
        $this->authorizeView();

        $tenantId = $this->currentTenant->tenant()->id;
        $items = [];

        foreach (self::MONITOR_SCHEDULE_LABELS as $monitorKey => $label) {
            $policy = TenantMonitorSchedulePolicy::ensureDefault($tenantId, $monitorKey);
            $items[] = $this->schedulePublicArray($policy, $label);
        }

        return response()->json(['data' => $items]);
    }

    /**
     * Atualiza o dia do mês (1–28) de um monitor do escritório corrente.
     */
    public function updateMonitorSchedule(Request $request, string $monitorKey): JsonResponse
    {
        $this->authorizeManage();
        $request->request->remove('tenant_id');

        if (! array_key_exists($monitorKey, self::MONITOR_SCHEDULE_LABELS)) {
            return response()->json([
                'message' => 'Monitor desconhecido para agendamento.',
            ], 404);
        }

        $data = $request->validate([
            'day_of_month' => ['required', 'integer', 'min:1', 'max:28'],
            'tenant_id' => ['prohibited'],
        ]);

        $tenant = $this->currentTenant->tenant();
        $policy = TenantMonitorSchedulePolicy::setCustomDay(
            $tenant->id,
            $monitorKey,
            (int) $data['day_of_month'],
            $request->user()?->id,
            'America/Sao_Paulo',
        );

        $this->audit->record('tenant.monitor_schedule.update', 'SUCCESS', $policy, [
            'monitor_key' => $monitorKey,
            'day_of_month' => $policy->day_of_month,
            'is_custom' => $policy->is_custom,
        ], $request->user()?->id, $tenant->id);

        return response()->json([
            'data' => $this->schedulePublicArray($policy, self::MONITOR_SCHEDULE_LABELS[$monitorKey]),
        ]);
    }

    /**
     * Estado de onboarding acionável (sem jargão OAuth/mTLS).
     */
    public function onboardingStatus(): JsonResponse
    {
        $this->authorizeView();

        $tenant = $this->currentTenant->tenant();
        $status = $this->actionableStatus->forTenant($tenant);
        $onboarding = $status['onboarding'] ?? [];
        $actions = [];

        foreach ($status['actionable'] ?? [] as $item) {
            $code = (string) ($item['code'] ?? 'ACTION_REQUIRED');
            $actions[] = [
                'code' => $code,
                'label' => self::ACTIONABLE_LABELS[$code] ?? 'Ação necessária',
                'description' => isset($item['message']) ? (string) $item['message'] : null,
                'href' => null,
            ];
        }

        if ($actions === [] && in_array(($onboarding['status'] ?? null), ['incomplete', 'configuring'], true)) {
            $prereq = $status['prerequisites'] ?? [];
            if (! ($prereq['profile'] ?? true)) {
                $actions[] = [
                    'code' => 'COMPLETE_PROFILE',
                    'label' => self::ACTIONABLE_LABELS['COMPLETE_PROFILE'],
                    'description' => 'Preencha CNPJ, razão social, e-mail e telefone institucionais.',
                    'href' => null,
                ];
            }
            if (! ($prereq['consent'] ?? true)) {
                $actions[] = [
                    'code' => 'ACCEPT_CONSENT',
                    'label' => self::ACTIONABLE_LABELS['ACCEPT_CONSENT'],
                    'description' => 'Aceite o consentimento técnico para uso do certificado.',
                    'href' => null,
                ];
            }
            if (! ($prereq['certificate'] ?? true)) {
                $actions[] = [
                    'code' => 'UPLOAD_CERTIFICATE',
                    'label' => self::ACTIONABLE_LABELS['UPLOAD_CERTIFICATE'],
                    'description' => 'Envie o certificado do escritório.',
                    'href' => null,
                ];
            }
        }

        $message = null;
        if (isset($onboarding['actionable']['message'])) {
            $message = (string) $onboarding['actionable']['message'];
        } elseif ($actions !== []) {
            $message = (string) ($actions[0]['description'] ?? null);
        }

        $environment = FiscalProfile::configured()->serproEnvironment();
        $procuracaoCounts = ClientProcuracaoSync::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('environment', $environment->value)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
        $moduleDecisions = array_map(
            fn (FiscalControlModule $module): array => $this->moduleAvailability
                ->resolve($module, $tenant, FiscalOperationClass::Read)
                ->toArray(),
            FiscalControlModule::cases(),
        );
        $initialRuns = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('trigger', 'SCHEDULED');

        return response()->json([
            'data' => [
                'status' => $onboarding['status'] ?? 'incomplete',
                'stage' => $this->onboardingStage((string) ($onboarding['status'] ?? 'incomplete')),
                'actions' => $actions,
                'correlation_id' => $status['correlation_id'] ?? null,
                'message' => $message,
                'modules' => $moduleDecisions,
                'procuracoes' => [
                    'total_clients' => Client::query()->withoutGlobalScopes()
                        ->where('tenant_id', $tenant->id)->where('is_active', true)->count(),
                    'by_status' => $procuracaoCounts,
                    'verified' => collect([
                        ClientProcuracaoSyncStatus::Authorized->value,
                        ClientProcuracaoSyncStatus::Missing->value,
                        ClientProcuracaoSyncStatus::Expired->value,
                    ])->sum(fn (string $status): int => (int) ($procuracaoCounts[$status] ?? 0)),
                ],
                'initial_collection' => [
                    'queued_at' => $onboarding['initial_collection_queued_at'] ?? null,
                    'runs_total' => (clone $initialRuns)->count(),
                    'runs_pending' => (clone $initialRuns)->whereIn('status', ['QUEUED', 'RUNNING'])->count(),
                    'runs_finished' => (clone $initialRuns)->whereIn('status', ['COMPLETED', 'BLOCKED', 'FAILED', 'SKIPPED'])->count(),
                ],
            ],
        ]);
    }

    private function onboardingStage(string $status): string
    {
        return match (TenantSerproOnboardingStatus::tryFrom($status)) {
            TenantSerproOnboardingStatus::Configuring,
            TenantSerproOnboardingStatus::Incomplete => 'CONFIGURANDO',
            TenantSerproOnboardingStatus::Validating => 'VALIDANDO',
            TenantSerproOnboardingStatus::Authorizing,
            TenantSerproOnboardingStatus::Provisioning => 'AUTORIZANDO',
            TenantSerproOnboardingStatus::LoadingProxyPowers => 'CARREGANDO_PROCURACOES',
            TenantSerproOnboardingStatus::Syncing => 'SINCRONIZANDO',
            TenantSerproOnboardingStatus::Ready,
            TenantSerproOnboardingStatus::Authorized => 'PRONTO',
            TenantSerproOnboardingStatus::ActionRequired => 'ACAO_NECESSARIA',
            TenantSerproOnboardingStatus::TechnicalError => 'FALHA_TECNICA',
            TenantSerproOnboardingStatus::Revoked => 'REVOGADO',
            default => 'CONFIGURANDO',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePublicArray(TenantMonitorSchedulePolicy $policy, string $label): array
    {
        return [
            'monitor_key' => $policy->monitor_key,
            'monitor_label' => $label,
            'day_of_month' => $policy->day_of_month,
            'is_default' => ! $policy->is_custom,
            'timezone' => $policy->timezone ?? 'America/Sao_Paulo',
            'next_run_at' => null,
            'last_run_at' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function certificatePayload(TenantCredential $credential): array
    {
        $links = TenantCredentialPurposeLink::query()
            ->where('tenant_credential_id', $credential->id)
            ->where('status', 'ACTIVE')
            ->orderBy('purpose')
            ->get()
            ->map(fn (TenantCredentialPurposeLink $link) => $link->toPublicArray())
            ->values()
            ->all();

        return [
            'certificate' => $credential->toPublicArray(),
            'purpose_links' => $links,
            'alerts' => $this->credentials->panelExpiryAlerts($credential),
        ];
    }

    private function authorizeView(): void
    {
        $policy = app(TenantSettingsPolicy::class);
        if (! $policy->view(auth()->user())) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }

    private function authorizeManage(): void
    {
        $policy = app(TenantSettingsPolicy::class);
        if (! $policy->manage(auth()->user())) {
            abort(403, 'Apenas administradores do escritório podem alterar a configuração.');
        }
    }
}

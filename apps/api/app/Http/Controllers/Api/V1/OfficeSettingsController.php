<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\FiscalControlModule;
use App\Enums\FiscalOperationClass;
use App\Enums\FiscalProfile;
use App\Enums\OfficeSerproOnboardingStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Office\GrantOfficeTechnicalConsentRequest;
use App\Http\Requests\Office\RemoveOfficeCanonicalCredentialRequest;
use App\Http\Requests\Office\UpdateOfficeInstitutionalProfileRequest;
use App\Models\Client;
use App\Models\ClientProcuracaoSnapshot;
use App\Models\FiscalMonitoringRun;
use App\Models\OfficeCredential;
use App\Models\OfficeCredentialPurposeLink;
use App\Models\OfficeMonitorSchedulePolicy;
use App\Policies\OfficeSettingsPolicy;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\OfficeCredentialService;
use App\Services\Certificates\OfficeInstitutionalProfileService;
use App\Services\Certificates\OfficeTechnicalConsentService;
use App\Services\Fiscal\Availability\FiscalModuleAvailabilityService;
use App\Services\Integra\OfficeSerproAuthorizationService;
use App\Services\Integra\OfficeSerproOnboardingService;
use App\Services\Integra\SerproTenantActionableStatusService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Superfície tenant-scoped de /settings: perfil, consentimento e A1 canônico.
 * Sem download/recuperação de PFX/senha; office_id só via CurrentOffice.
 */
class OfficeSettingsController extends Controller
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
        'UPLOAD_A1' => 'Enviar certificado A1',
        'UPLOAD_TERMO' => 'Regularizar Termo de autorização',
        'SIGNATURE_REQUIRED' => 'Assinatura do Termo pendente',
        'REONBOARD_REQUIRED' => 'Reativar integrações',
        'PLATFORM_UNAVAILABLE' => 'Aguardar suporte da plataforma',
        'MISSING_PROFILE' => 'Completar perfil',
        'MISSING_CONSENT' => 'Aceitar consentimento',
        'MISSING_A1' => 'Enviar certificado A1',
    ];

    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly OfficeInstitutionalProfileService $profiles,
        private readonly OfficeCredentialService $credentials,
        private readonly OfficeTechnicalConsentService $consents,
        private readonly SerproTenantActionableStatusService $actionableStatus,
        private readonly FiscalModuleAvailabilityService $moduleAvailability,
        private readonly OfficeSerproOnboardingService $onboarding,
        private readonly OfficeSerproAuthorizationService $authorizations,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): JsonResponse
    {
        $this->authorizeView();

        $profile = $this->profiles->forCurrentOffice();
        $credential = $this->credentials->activeCanonicalForCurrentOffice();
        // Atualiza flags de alerta (dedupe) antes de expor no painel.
        $this->credentials->refreshExpiryAlerts();
        $credential = $credential?->fresh() ?? $this->credentials->activeCanonicalForCurrentOffice();

        $links = [];
        if ($credential !== null) {
            $links = OfficeCredentialPurposeLink::query()
                ->where('office_credential_id', $credential->id)
                ->where('status', 'ACTIVE')
                ->orderBy('purpose')
                ->get()
                ->map(fn (OfficeCredentialPurposeLink $link) => $link->toPublicArray())
                ->values()
                ->all();
        }

        return response()->json([
            'data' => [
                'profile' => $profile->toPublicArray(),
                'consent' => $this->consents->currentStatus(),
                'credential' => $credential?->toPublicArray(),
                'purpose_links' => $links,
                'alerts' => $this->credentials->panelExpiryAlerts($credential),
            ],
        ]);
    }

    public function updateProfile(UpdateOfficeInstitutionalProfileRequest $request): JsonResponse
    {
        $this->authorizeManage();

        try {
            $result = $this->profiles->update(
                $request->validated(),
                $request->user()?->id,
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->audit->record('office.institutional_profile.update', 'FAILED', null, [
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

    public function grantConsent(GrantOfficeTechnicalConsentRequest $request): JsonResponse
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
        $request->request->remove('office_id');

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

    public function showCredential(): JsonResponse
    {
        $this->authorizeView();

        $this->credentials->refreshExpiryAlerts();
        $credential = $this->credentials->activeCanonicalForCurrentOffice();

        return response()->json([
            'data' => [
                'credential' => $credential?->toPublicArray(),
                'alerts' => $this->credentials->panelExpiryAlerts($credential),
            ],
        ]);
    }

    public function storeCredential(Request $request): JsonResponse
    {
        $this->authorizeManage();
        $request->request->remove('office_id');

        $data = $request->validate([
            'pfx' => ['required', 'file', 'max:5120'],
            'password' => ['required', 'string'],
            'consent_accepted' => ['required', 'accepted'],
            'office_id' => ['prohibited'],
        ]);

        try {
            $binary = file_get_contents($data['pfx']->getRealPath());
            if ($binary === false) {
                throw new RuntimeException('Falha ao ler arquivo PFX.');
            }

            // Consentimento antes do cutover: se grant falhar, o A1 não fica ativo sem registro.
            if (($this->consents->currentStatus()['requires_consent'] ?? true) === true) {
                $this->consents->grant(true, $request->user()?->id);
            }
            $credential = $this->credentials->activateCanonical(
                $binary,
                $data['password'],
                $request->user()?->id,
            );
            unset($binary, $data['password']);
        } catch (RuntimeException $e) {
            $this->audit->record('office_credential.canonical.activate', 'FAILED', null, [
                'message' => $e->getMessage() ?: 'Falha ao ativar certificado canônico.',
            ], $request->user()?->id);

            return response()->json([
                'message' => $e->getMessage() ?: 'Falha ao ativar certificado canônico.',
            ], 422);
        } catch (Throwable $e) {
            report($e);
            $this->audit->record('office_credential.canonical.activate', 'FAILED', null, [
                'message' => 'Falha ao ativar certificado canônico.',
            ], $request->user()?->id);

            return response()->json([
                'message' => 'Falha ao ativar certificado canônico.',
            ], 422);
        }

        $this->audit->record('office_credential.canonical.activate', 'SUCCESS', $credential, [
            'fingerprint_sha256' => $credential->fingerprint_sha256,
            'holder_cnpj' => $credential->holder_cnpj,
            'valid_to' => $credential->valid_to?->toIso8601String(),
            'purpose' => $credential->purpose->value,
        ], $request->user()?->id);

        $office = $this->currentOffice->office();

        return response()->json([
            'data' => array_merge($this->credentialPayload($credential), [
                'onboarding' => $this->actionableStatus->forOffice($office)['onboarding'] ?? null,
            ]),
        ], 202);
    }

    public function replaceCredential(Request $request): JsonResponse
    {
        $this->authorizeManage();
        $request->request->remove('office_id');

        $data = $request->validate([
            'pfx' => ['required', 'file', 'max:5120'],
            'password' => ['required', 'string'],
            'consent_accepted' => ['required', 'accepted'],
            'office_id' => ['prohibited'],
        ]);

        $previous = $this->credentials->activeCanonicalForCurrentOffice();
        $previousFingerprint = $previous?->fingerprint_sha256;

        try {
            $binary = file_get_contents($data['pfx']->getRealPath());
            if ($binary === false) {
                throw new RuntimeException('Falha ao ler arquivo PFX.');
            }

            // Consentimento antes do cutover: se grant falhar, o A1 não fica ativo sem registro.
            if (($this->consents->currentStatus()['requires_consent'] ?? true) === true) {
                $this->consents->grant(true, $request->user()?->id);
            }
            $credential = $this->credentials->replaceCanonical(
                $binary,
                $data['password'],
                $request->user()?->id,
            );
            unset($binary, $data['password']);
        } catch (RuntimeException $e) {
            // Validate-before-cutover: credencial anterior permanece.
            $stillActive = $this->credentials->activeCanonicalForCurrentOffice();
            $this->audit->record('office_credential.canonical.replace', 'FAILED', $stillActive, [
                'message' => $e->getMessage() ?: 'Falha ao substituir certificado canônico.',
                'previous_fingerprint_sha256' => $previousFingerprint,
                'previous_still_active' => $stillActive !== null
                    && $stillActive->fingerprint_sha256 === $previousFingerprint,
            ], $request->user()?->id);

            return response()->json([
                'message' => $e->getMessage() ?: 'Falha ao substituir certificado canônico.',
                'previous_preserved' => true,
            ], 422);
        } catch (Throwable $e) {
            report($e);
            $this->audit->record('office_credential.canonical.replace', 'FAILED', null, [
                'message' => 'Falha ao substituir certificado canônico.',
            ], $request->user()?->id);

            return response()->json([
                'message' => 'Falha ao substituir certificado canônico.',
                'previous_preserved' => true,
            ], 422);
        }

        $this->audit->record('office_credential.canonical.replace', 'SUCCESS', $credential, [
            'fingerprint_sha256' => $credential->fingerprint_sha256,
            'previous_fingerprint_sha256' => $previousFingerprint,
            'holder_cnpj' => $credential->holder_cnpj,
            'valid_to' => $credential->valid_to?->toIso8601String(),
        ], $request->user()?->id);

        $office = $this->currentOffice->office();

        return response()->json([
            'data' => array_merge($this->credentialPayload($credential), [
                'onboarding' => $this->actionableStatus->forOffice($office)['onboarding'] ?? null,
            ]),
        ], 202);
    }

    public function removeCredential(RemoveOfficeCanonicalCredentialRequest $request): JsonResponse
    {
        $this->authorizeManage();

        try {
            $credential = $this->credentials->removeCanonical(
                confirmed: true,
                actorUserId: $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if ($credential === null) {
            return response()->json([
                'message' => 'Não há certificado A1 ativo para remover.',
            ], 422);
        }

        $this->audit->record('office_credential.canonical.remove', 'SUCCESS', $credential, [
            'fingerprint_sha256' => $credential->fingerprint_sha256,
            'holder_cnpj' => $credential->holder_cnpj,
        ], $request->user()?->id);

        return response()->json([
            'data' => [
                'credential' => $credential->toPublicArray(),
                'removed' => true,
            ],
        ]);
    }

    /**
     * Regenera a integração SERPRO do escritório sem reenviar o A1.
     * Usa o certificado e o consentimento já ativos.
     */
    public function refreshIntegration(Request $request): JsonResponse
    {
        $this->authorizeManage();
        $office = $this->currentOffice->office();
        $env = FiscalProfile::configured()->serproEnvironment();

        if ($this->credentials->activeCanonicalForCurrentOffice() === null) {
            return response()->json([
                'message' => 'Envie o certificado A1 do escritório antes de atualizar a integração.',
            ], 422);
        }

        try {
            $this->onboarding->ensureAuthorFromCanonicalSetup(
                $office,
                $env,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            $this->audit->record('office.integration.refresh', 'FAILED', null, [
                'message' => $e->getMessage(),
                'environment' => $env->value,
                'stage' => 'ensure_author',
            ], $request->user()?->id, $office->id);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $auth = null;
        $refreshError = null;
        try {
            $auth = $this->authorizations->refreshProcuradorToken(
                $office,
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
                $office,
                $env,
                $request->user()?->id,
                force: true,
            );
        } catch (RuntimeException $e) {
            $this->audit->record('office.integration.refresh', 'FAILED', null, [
                'message' => $e->getMessage(),
                'environment' => $env->value,
                'stage' => 'onboarding',
                'refresh_error' => $refreshError?->getMessage(),
            ], $request->user()?->id, $office->id);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $auth ??= $this->authorizations->getOrCreate($office, $env);

        if ($refreshError !== null) {
            $this->audit->record('office.integration.refresh', 'PARTIAL', $auth, [
                'environment' => $env->value,
                'status' => $auth->status->value,
                'message' => $refreshError->getMessage(),
                'onboarding_evaluated' => true,
            ], $request->user()?->id, $office->id);

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

        $this->audit->record('office.integration.refresh', 'SUCCESS', $auth, [
            'environment' => $env->value,
            'status' => $auth->status->value,
        ], $request->user()?->id, $office->id);

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

        $officeId = $this->currentOffice->office()->id;
        $items = [];

        foreach (self::MONITOR_SCHEDULE_LABELS as $monitorKey => $label) {
            $policy = OfficeMonitorSchedulePolicy::ensureDefault($officeId, $monitorKey);
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
        $request->request->remove('office_id');

        if (! array_key_exists($monitorKey, self::MONITOR_SCHEDULE_LABELS)) {
            return response()->json([
                'message' => 'Monitor desconhecido para agendamento.',
            ], 404);
        }

        $data = $request->validate([
            'day_of_month' => ['required', 'integer', 'min:1', 'max:28'],
            'office_id' => ['prohibited'],
        ]);

        $office = $this->currentOffice->office();
        $policy = OfficeMonitorSchedulePolicy::setCustomDay(
            $office->id,
            $monitorKey,
            (int) $data['day_of_month'],
            $request->user()?->id,
            'America/Sao_Paulo',
        );

        $this->audit->record('office.monitor_schedule.update', 'SUCCESS', $policy, [
            'monitor_key' => $monitorKey,
            'day_of_month' => $policy->day_of_month,
            'is_custom' => $policy->is_custom,
        ], $request->user()?->id, $office->id);

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

        $office = $this->currentOffice->office();
        $status = $this->actionableStatus->forOffice($office);
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
                    'description' => 'Aceite o consentimento técnico para uso do A1.',
                    'href' => null,
                ];
            }
            if (! ($prereq['a1'] ?? true)) {
                $actions[] = [
                    'code' => 'UPLOAD_A1',
                    'label' => self::ACTIONABLE_LABELS['UPLOAD_A1'],
                    'description' => 'Envie o certificado e-CNPJ A1 canônico do escritório.',
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
        $procuracaoCounts = ClientProcuracaoSnapshot::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('environment', $environment->value)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($count): int => (int) $count)
            ->all();
        $moduleDecisions = array_map(
            fn (FiscalControlModule $module): array => $this->moduleAvailability
                ->resolve($module, $office, FiscalOperationClass::Read)
                ->toArray(),
            FiscalControlModule::cases(),
        );
        $initialRuns = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
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
                        ->where('office_id', $office->id)->where('is_active', true)->count(),
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
        return match (OfficeSerproOnboardingStatus::tryFrom($status)) {
            OfficeSerproOnboardingStatus::Configuring,
            OfficeSerproOnboardingStatus::Incomplete => 'CONFIGURANDO',
            OfficeSerproOnboardingStatus::Validating => 'VALIDANDO',
            OfficeSerproOnboardingStatus::Authorizing,
            OfficeSerproOnboardingStatus::Provisioning => 'AUTORIZANDO',
            OfficeSerproOnboardingStatus::LoadingProxyPowers => 'CARREGANDO_PROCURACOES',
            OfficeSerproOnboardingStatus::Syncing => 'SINCRONIZANDO',
            OfficeSerproOnboardingStatus::Ready,
            OfficeSerproOnboardingStatus::Authorized => 'PRONTO',
            OfficeSerproOnboardingStatus::ActionRequired => 'ACAO_NECESSARIA',
            OfficeSerproOnboardingStatus::TechnicalError => 'FALHA_TECNICA',
            OfficeSerproOnboardingStatus::Revoked => 'REVOGADO',
            default => 'CONFIGURANDO',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePublicArray(OfficeMonitorSchedulePolicy $policy, string $label): array
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
    private function credentialPayload(OfficeCredential $credential): array
    {
        $links = OfficeCredentialPurposeLink::query()
            ->where('office_credential_id', $credential->id)
            ->where('status', 'ACTIVE')
            ->orderBy('purpose')
            ->get()
            ->map(fn (OfficeCredentialPurposeLink $link) => $link->toPublicArray())
            ->values()
            ->all();

        return [
            'credential' => $credential->toPublicArray(),
            'purpose_links' => $links,
            'alerts' => $this->credentials->panelExpiryAlerts($credential),
        ];
    }

    private function authorizeView(): void
    {
        $policy = app(OfficeSettingsPolicy::class);
        if (! $policy->view(auth()->user())) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }

    private function authorizeManage(): void
    {
        $policy = app(OfficeSettingsPolicy::class);
        if (! $policy->manage(auth()->user())) {
            abort(403, 'Apenas administradores do escritório podem alterar a configuração.');
        }
    }
}

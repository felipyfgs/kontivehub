<?php

namespace App\Services\Integra;

use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\CredentialStatus;
use App\Enums\FiscalControlModule;
use App\Enums\FiscalProfile;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEnvironment;
use App\Enums\TenantCredentialPurpose;
use App\Enums\TenantSerproOnboardingStatus;
use App\Enums\TermoAuthorizationState;
use App\Jobs\Fiscal\RecoverFiscalModuleJob;
use App\Jobs\Serpro\BeginTenantFiscalReadinessJob;
use App\Jobs\Serpro\ProcessTenantSerproOnboardingJob;
use App\Jobs\Serpro\SignTermoWithManagedCertificateJob;
use App\Models\Tenant;
use App\Models\TenantCredential;
use App\Models\TenantCredentialPurposeLink;
use App\Models\TenantInstitutionalProfile;
use App\Models\TenantSerproAuthorization;
use App\Models\TenantSerproOnboardingState;
use App\Models\TenantTechnicalConsent;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * State machine + enqueue de onboarding SERPRO automatizado (F-3.1).
 * Deriva estado de perfil/consentimento/certificado + TenantSerproAuthorization.
 */
final class TenantSerproOnboardingService
{
    public function __construct(
        private readonly TenantSerproAuthorizationService $authorizations,
        private readonly AuditLogger $audit,
    ) {}

    public function getOrCreateState(Tenant $tenant, SerproEnvironment $environment): TenantSerproOnboardingState
    {
        return TenantSerproOnboardingState::query()
            ->withoutGlobalScopes()
            ->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'environment' => $environment->value,
                ],
                [
                    'status' => TenantSerproOnboardingStatus::Incomplete,
                    'last_transition_at' => now(),
                ],
            );
    }

    /**
     * Reavalia pré-requisitos e, se ready, enfileira job idempotente.
     *
     * @return array{
     *   state: TenantSerproOnboardingState,
     *   enqueued: bool,
     *   prerequisites: array<string, bool>
     * }
     */
    public function evaluateAndMaybeEnqueue(
        Tenant $tenant,
        SerproEnvironment $environment,
        ?int $actorUserId = null,
        ?string $correlationId = null,
        bool $force = false,
    ): array {
        $state = $this->getOrCreateState($tenant, $environment);
        $this->ensureAuthorFromCertificate($tenant, $environment, $actorUserId);
        $prereq = $this->evaluatePrerequisites($tenant, $environment);
        $correlationId ??= (string) Str::uuid();

        if ($state->status === TenantSerproOnboardingStatus::Revoked) {
            return ['state' => $state, 'enqueued' => false, 'prerequisites' => $prereq];
        }

        if (in_array($state->status, [TenantSerproOnboardingStatus::Authorized, TenantSerproOnboardingStatus::Ready], true) && ! $force) {
            $this->clearActionable($state);

            return ['state' => $state->refresh(), 'enqueued' => false, 'prerequisites' => $prereq];
        }

        if (! $prereq['complete']) {
            $this->transition(
                $state,
                TenantSerproOnboardingStatus::Configuring,
                lastStep: 'prerequisites',
                actionableCode: $prereq['missing_code'],
                actionableMessage: $prereq['missing_message'],
                correlationId: $correlationId,
            );

            return ['state' => $state->refresh(), 'enqueued' => false, 'prerequisites' => $prereq];
        }

        if (FiscalProfile::configured() === FiscalProfile::Dev) {
            // Dev usa autenticar_procurador=fixture → DisabledAutenticarProcuradorClient.
            // Sem token local a elegibilidade bloqueia toda a carteira (ACTION_REQUIRED).
            $this->authorizations->activateDevFixtureAuthorization($tenant, $environment, $actorUserId);

            $idempotencyKey = $this->buildIdempotencyKey($tenant, $environment, $prereq['fingerprint']);
            $this->transition(
                $state,
                TenantSerproOnboardingStatus::Ready,
                lastStep: 'ready_fixture',
                correlationId: $correlationId,
                idempotencyKey: $idempotencyKey,
                readyAt: now(),
                authorizedAt: now(),
                clearTechnical: true,
                clearActionable: true,
            );

            return ['state' => $state->refresh(), 'enqueued' => false, 'prerequisites' => $prereq];
        }

        if (
            in_array($state->status, [
                TenantSerproOnboardingStatus::Provisioning,
                TenantSerproOnboardingStatus::Validating,
                TenantSerproOnboardingStatus::Authorizing,
                TenantSerproOnboardingStatus::LoadingProxyPowers,
                TenantSerproOnboardingStatus::Syncing,
                TenantSerproOnboardingStatus::Authorized,
                TenantSerproOnboardingStatus::Ready,
            ], true)
            && ! $force
        ) {
            // Idempotência: já em andamento / autorizado
            return ['state' => $state, 'enqueued' => false, 'prerequisites' => $prereq];
        }

        $idempotencyKey = $this->buildIdempotencyKey($tenant, $environment, $prereq['fingerprint']);

        if (! $force && $state->idempotency_key === $idempotencyKey
            && in_array($state->status, [
                TenantSerproOnboardingStatus::Validating,
                TenantSerproOnboardingStatus::Authorizing,
                TenantSerproOnboardingStatus::LoadingProxyPowers,
                TenantSerproOnboardingStatus::Syncing,
            ], true)
        ) {
            return ['state' => $state, 'enqueued' => false, 'prerequisites' => $prereq];
        }

        // Pré-requisitos ok → validação auditável e enqueue.
        if ($state->status !== TenantSerproOnboardingStatus::Validating
            || $state->idempotency_key !== $idempotencyKey
        ) {
            $this->transition(
                $state,
                TenantSerproOnboardingStatus::Validating,
                lastStep: 'validating',
                correlationId: $correlationId,
                idempotencyKey: $idempotencyKey,
                readyAt: $state->ready_at ?? now(),
                clearTechnical: true,
                clearActionable: true,
            );
        }

        $this->transition(
            $state,
            TenantSerproOnboardingStatus::Validating,
            lastStep: 'enqueued',
            correlationId: $correlationId,
            idempotencyKey: $idempotencyKey,
            readyAt: $state->ready_at ?? now(),
            provisioningStartedAt: now(),
            clearTechnical: true,
            clearActionable: true,
        );

        ProcessTenantSerproOnboardingJob::dispatch(
            tenantId: (int) $tenant->id,
            environment: $environment->value,
            idempotencyKey: $idempotencyKey,
            actorUserId: $actorUserId,
            correlationId: $correlationId,
        );

        $this->audit->record('serpro.onboarding.enqueue', 'SUCCESS', $state, [
            'environment' => $environment->value,
            'idempotency_key' => $idempotencyKey,
        ], $actorUserId, $tenant->id);

        return ['state' => $state->refresh(), 'enqueued' => true, 'prerequisites' => $prereq];
    }

    /**
     * Execução interna do job (com lock).
     */
    public function process(
        Tenant $tenant,
        SerproEnvironment $environment,
        string $idempotencyKey,
        ?int $actorUserId = null,
        ?string $correlationId = null,
    ): TenantSerproOnboardingState {
        $lock = Cache::lock($this->lockKey($tenant, $environment), 120);
        if (! $lock->get()) {
            throw new RuntimeException('ONBOARDING_LOCK_BUSY');
        }

        try {
            $state = $this->getOrCreateState($tenant, $environment);

            if (
                $state->status === TenantSerproOnboardingStatus::Ready
                && $state->idempotency_key === $idempotencyKey
            ) {
                return $state;
            }

            $this->ensureAuthorFromCertificate($tenant, $environment, $actorUserId);
            $prereq = $this->evaluatePrerequisites($tenant, $environment);
            if (! $prereq['complete']) {
                $this->transition(
                    $state,
                    TenantSerproOnboardingStatus::Configuring,
                    lastStep: 'prerequisites',
                    actionableCode: $prereq['missing_code'],
                    actionableMessage: $prereq['missing_message'],
                    correlationId: $correlationId,
                );

                return $state->refresh();
            }

            $this->transition(
                $state,
                TenantSerproOnboardingStatus::Authorizing,
                lastStep: 'authorizing',
                correlationId: $correlationId,
                idempotencyKey: $idempotencyKey,
                provisioningStartedAt: $state->provisioning_started_at ?? now(),
                clearTechnical: true,
                clearActionable: true,
            );

            $auth = $this->authorizations->getOrCreate($tenant, $environment);

            // 1) Draft do Termo se ausente
            $meta = is_array($auth->metadata) ? $auth->metadata : [];
            if (empty($meta['termo_draft_vault_object_id']) && $auth->termo_vault_object_id === null) {
                $this->setStep($state, 'termo_draft');
                $this->authorizations->generateTermoDraft($tenant, $environment, null, $actorUserId);
                $auth = $auth->refresh();
            }

            // 2) Assinar com certificado gerenciado de forma síncrona (mesmo path do job dedicado)
            if ($auth->termo_vault_object_id === null) {
                $this->setStep($state, 'termo_sign');
                if ($auth->certificate_mode === AuthorCertificateMode::ManagedCertificate) {
                    if (empty($meta['termo_draft_vault_object_id'])) {
                        $this->authorizations->generateTermoDraft($tenant, $environment, null, $actorUserId);
                        $auth = $auth->refresh();
                    }

                    SignTermoWithManagedCertificateJob::dispatchSync(
                        $tenant->id,
                        $environment->value,
                        $auth->id,
                        $actorUserId,
                        $correlationId,
                    );
                    $auth = $auth->refresh();
                    if ($auth->termo_vault_object_id === null) {
                        $this->transition(
                            $state,
                            TenantSerproOnboardingStatus::ActionRequired,
                            lastStep: 'termo_sign',
                            actionableCode: 'UPLOAD_TERMO',
                            actionableMessage: 'Falha ao assinar o Termo com certificado gerenciado.',
                            correlationId: $correlationId,
                        );

                        return $state->refresh();
                    }
                } else {
                    $this->transition(
                        $state,
                        TenantSerproOnboardingStatus::ActionRequired,
                        lastStep: 'termo_sign',
                        actionableCode: 'UPLOAD_TERMO',
                        actionableMessage: 'Envie o Termo de Autorização assinado ou use o certificado gerenciado.',
                        correlationId: $correlationId,
                    );

                    return $state->refresh();
                }
            }

            // 3) Apoiar / token procurador
            $this->setStep($state, 'token_refresh');
            try {
                $auth = $this->authorizations->refreshProcuradorToken($tenant, $environment, $actorUserId);
            } catch (Throwable $e) {
                $this->markTechnicalError(
                    $state,
                    'APOIAR_FAILED',
                    $this->sanitizeTechnicalMessage($e->getMessage()),
                    $correlationId,
                );
                throw $e;
            }

            if ($auth->status === SerproAuthorizationStatus::ActionRequired) {
                $this->transition(
                    $state,
                    TenantSerproOnboardingStatus::ActionRequired,
                    lastStep: 'token_refresh',
                    actionableCode: 'SIGNATURE_REQUIRED',
                    actionableMessage: $auth->action_required_reason
                        ?? 'Ação necessária para renovar autorização SERPRO.',
                    correlationId: $correlationId,
                );

                return $state->refresh();
            }

            $tokenOk = $auth->procurador_token_vault_object_id !== null
                && $auth->procurador_token_expires_at !== null
                && $auth->procurador_token_expires_at->isFuture();

            $termoAccepted = in_array($auth->termo_authorization_state, [
                TermoAuthorizationState::SerproAccepted,
                TermoAuthorizationState::LocalValidated,
            ], true);

            if (! $tokenOk && ! $termoAccepted) {
                $this->markTechnicalError(
                    $state,
                    'TOKEN_MISSING',
                    'Token do procurador ausente após Apoiar.',
                    $correlationId,
                );

                return $state->refresh();
            }

            $this->transition(
                $state,
                TenantSerproOnboardingStatus::LoadingProxyPowers,
                lastStep: 'loading_proxy_powers',
                correlationId: $correlationId,
                idempotencyKey: $idempotencyKey,
                authorizedAt: now(),
                clearTechnical: true,
                clearActionable: true,
            );

            BeginTenantFiscalReadinessJob::dispatch(
                (int) $tenant->id,
                $environment->value,
                $idempotencyKey,
                $actorUserId,
                $correlationId,
            );

            $this->audit->record('serpro.onboarding.authorization_ready', 'SUCCESS', $state, [
                'environment' => $environment->value,
                'authorization_status' => $auth->status->value,
            ], $actorUserId, $tenant->id);

            return $state->refresh();
        } finally {
            $lock->release();
        }
    }

    public function finalizeReadiness(
        Tenant $tenant,
        SerproEnvironment $environment,
        string $idempotencyKey,
        ?int $actorUserId = null,
        ?string $correlationId = null,
        ?string $batchId = null,
    ): TenantSerproOnboardingState {
        $state = $this->getOrCreateState($tenant, $environment);
        if ($state->idempotency_key !== $idempotencyKey) {
            return $state;
        }

        $this->transition(
            $state,
            TenantSerproOnboardingStatus::Syncing,
            lastStep: 'initial_collection',
            correlationId: $correlationId,
            clearTechnical: true,
            clearActionable: true,
        );

        foreach (FiscalControlModule::cases() as $module) {
            RecoverFiscalModuleJob::dispatch($module->value, (int) $tenant->id, (int) ($actorUserId ?? 0));
        }

        $metadata = is_array($state->metadata) ? $state->metadata : [];
        $metadata['procuracao_batch_id'] = $batchId;
        $metadata['initial_collection_queued_at'] = now()->toIso8601String();
        $state->metadata = $metadata;
        $state->save();

        $this->transition(
            $state,
            TenantSerproOnboardingStatus::Ready,
            lastStep: 'ready',
            correlationId: $correlationId,
            authorizedAt: $state->authorized_at ?? now(),
            clearTechnical: true,
            clearActionable: true,
        );

        $this->audit->record('serpro.onboarding.ready', 'SUCCESS', $state, [
            'environment' => $environment->value,
            'procuracao_batch_id' => $batchId,
            'initial_modules_queued' => count(FiscalControlModule::cases()),
        ], $actorUserId, (int) $tenant->id);

        return $state->refresh();
    }

    public function reactToProfileOrCredentialChange(
        Tenant $tenant,
        SerproEnvironment $environment,
        string $reason,
        ?int $actorUserId = null,
    ): TenantSerproOnboardingState {
        $state = $this->getOrCreateState($tenant, $environment);

        if (in_array($state->status, [
            TenantSerproOnboardingStatus::Authorized,
            TenantSerproOnboardingStatus::Provisioning,
            TenantSerproOnboardingStatus::Validating,
            TenantSerproOnboardingStatus::Authorizing,
            TenantSerproOnboardingStatus::LoadingProxyPowers,
            TenantSerproOnboardingStatus::Syncing,
            TenantSerproOnboardingStatus::Ready,
        ], true)) {
            $this->transition(
                $state,
                TenantSerproOnboardingStatus::ActionRequired,
                lastStep: 'invalidate_'.$reason,
                actionableCode: 'REONBOARD_REQUIRED',
                actionableMessage: 'Perfil, consentimento ou certificado alterados — reonboarding necessário.',
            );

            try {
                $auth = $this->authorizations->getOrCreate($tenant, $environment);
                $this->authorizations->invalidateDerivedAuthorization(
                    $auth,
                    $tenant,
                    $environment,
                    reason: $reason,
                    actorUserId: $actorUserId,
                );
            } catch (Throwable) {
            }
        }

        return $this->evaluateAndMaybeEnqueue($tenant, $environment, $actorUserId)['state'];
    }

    /**
     * @return array{
     *   complete: bool,
     *   profile: bool,
     *   consent: bool,
     *   certificate: bool,
     *   author: bool,
     *   missing_code: ?string,
     *   missing_message: ?string,
     *   fingerprint: string
     * }
     */
    public function evaluatePrerequisites(Tenant $tenant, SerproEnvironment $environment): array
    {
        $auth = TenantSerproAuthorization::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment', $environment->value)
            ->first();

        $profileOk = $this->hasInstitutionalProfile($tenant);
        $consentOk = $this->hasTechnicalConsent($tenant);
        $tenantCertificate = $this->activeSigningCredential((int) $tenant->id);
        // External signature path: certificado not required if author configured (tenant signs offline)
        $externalOk = $auth !== null
            && $auth->certificate_mode === AuthorCertificateMode::ExternalSignature
            && $auth->author_identity !== ''
            && $auth->author_identity !== '00000000000000';

        $authorOk = $auth !== null
            && $auth->author_identity !== ''
            && $auth->author_identity !== '00000000000000';

        $credentialOk = $tenantCertificate !== null || $externalOk;

        $missingCode = null;
        $missingMessage = null;
        if (! $profileOk) {
            $missingCode = 'COMPLETE_PROFILE';
            $missingMessage = 'Complete o perfil institucional (CNPJ, razão social, e-mail e telefone).';
        } elseif (! $consentOk) {
            $missingCode = 'CONSENT_REQUIRED';
            $missingMessage = 'Aceite o consentimento técnico vigente para uso do certificado.';
        } elseif (! $authorOk) {
            $missingCode = 'AUTHOR_REQUIRED';
            $missingMessage = 'Identidade do autor do pedido ainda não está disponível a partir do perfil.';
        } elseif (! $credentialOk) {
            $missingCode = 'CERTIFICATE_REQUIRED';
            $missingMessage = 'Envie o certificado do escritório.';
        }

        $complete = $profileOk && $consentOk && $authorOk && $credentialOk;
        $fingerprint = hash('sha256', implode('|', [
            (string) $tenant->id,
            $environment->value,
            $auth?->author_identity ?? '',
            $auth?->certificate_mode?->value ?? '',
            $tenantCertificate?->fingerprint_sha256 ?? '',
            $complete ? '1' : '0',
        ]));

        return [
            'complete' => $complete,
            'profile' => $profileOk,
            'consent' => $consentOk,
            'certificate' => $credentialOk,
            'author' => $authorOk,
            'missing_code' => $missingCode,
            'missing_message' => $missingMessage,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Deriva autor ManagedCertificate do perfil + certificado + consentimento técnico
     * (sem UI técnica SERPRO em /conta/escritorio).
     */
    public function ensureAuthorFromCertificate(
        Tenant $tenant,
        SerproEnvironment $environment,
        ?int $actorUserId = null,
    ): void {
        $profile = TenantInstitutionalProfile::query()
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($profile === null || ! $profile->isComplete()) {
            return;
        }

        if ($this->activeSigningCredential((int) $tenant->id) === null) {
            return;
        }

        $auth = $this->authorizations->getOrCreate($tenant, $environment);
        if (! $this->hasTechnicalConsent($tenant)) {
            return;
        }

        $identity = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $profile->cnpj) ?? '');
        if (strlen($identity) !== 14) {
            return;
        }

        $placeholder = $auth->author_identity === '' || $auth->author_identity === '00000000000000';
        $needsAuthorSync = $placeholder
            || $auth->author_identity !== $identity
            || $auth->certificate_mode !== AuthorCertificateMode::ManagedCertificate;

        if ($needsAuthorSync) {
            $this->authorizations->configureAuthor(
                $tenant,
                $environment,
                AuthorIdentityType::Cnpj,
                $identity,
                $profile->legal_name,
                AuthorCertificateMode::ManagedCertificate,
                $actorUserId,
            );
        }
    }

    private function activeSigningCredential(int $tenantId): ?TenantCredential
    {
        $link = TenantCredentialPurposeLink::query()
            ->where('tenant_id', $tenantId)
            ->where('purpose', TenantCredentialPurpose::SerproTermSigning)
            ->where('status', CredentialStatus::Active)
            ->whereNull('revoked_at')
            ->with('credential')
            ->latest('id')
            ->first();

        $credential = $link?->credential;

        return $credential !== null && $credential->status->isUsable()
            ? $credential
            : null;
    }

    private function hasInstitutionalProfile(Tenant $tenant): bool
    {
        $profile = TenantInstitutionalProfile::query()
            ->where('tenant_id', $tenant->id)
            ->first();

        return $profile?->isComplete() ?? false;
    }

    private function hasTechnicalConsent(Tenant $tenant): bool
    {
        $consent = TenantTechnicalConsent::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->first();

        return $consent?->isActive() ?? false;
    }

    private function buildIdempotencyKey(Tenant $tenant, SerproEnvironment $environment, string $fingerprint): string
    {
        return substr(hash('sha256', 'onboard|'.$tenant->id.'|'.$environment->value.'|'.$fingerprint), 0, 64);
    }

    private function lockKey(Tenant $tenant, SerproEnvironment $environment): string
    {
        return sprintf('serpro:onboarding:%d:%s', $tenant->id, $environment->value);
    }

    private function transition(
        TenantSerproOnboardingState $state,
        TenantSerproOnboardingStatus $to,
        ?string $lastStep = null,
        ?string $actionableCode = null,
        ?string $actionableMessage = null,
        ?string $technicalCode = null,
        ?string $technicalMessage = null,
        ?string $correlationId = null,
        ?string $idempotencyKey = null,
        mixed $readyAt = false,
        mixed $provisioningStartedAt = false,
        mixed $authorizedAt = false,
        bool $clearTechnical = false,
        bool $clearActionable = false,
    ): void {
        $state->status = $to;
        if ($lastStep !== null) {
            $state->last_step = $lastStep;
        }
        if ($clearActionable) {
            $state->actionable_code = null;
            $state->actionable_message = null;
        } elseif ($actionableCode !== null) {
            $state->actionable_code = $actionableCode;
            $state->actionable_message = $actionableMessage !== null
                ? mb_substr($actionableMessage, 0, 500)
                : null;
        }
        if ($clearTechnical) {
            $state->technical_code = null;
            $state->technical_message = null;
        } elseif ($technicalCode !== null) {
            $state->technical_code = $technicalCode;
            $state->technical_message = $technicalMessage !== null
                ? mb_substr($technicalMessage, 0, 500)
                : null;
        }
        if ($correlationId !== null) {
            $state->correlation_id = $correlationId;
        }
        if ($idempotencyKey !== null) {
            $state->idempotency_key = $idempotencyKey;
        }
        if ($readyAt !== false) {
            $state->ready_at = $readyAt;
        }
        if ($provisioningStartedAt !== false) {
            $state->provisioning_started_at = $provisioningStartedAt;
        }
        if ($authorizedAt !== false) {
            $state->authorized_at = $authorizedAt;
        }
        $state->last_transition_at = now();
        $state->save();
    }

    private function setStep(TenantSerproOnboardingState $state, string $step): void
    {
        $state->last_step = $step;
        $state->last_transition_at = now();
        $state->save();
    }

    private function markTechnicalError(
        TenantSerproOnboardingState $state,
        string $code,
        string $message,
        ?string $correlationId,
    ): void {
        $this->transition(
            $state,
            TenantSerproOnboardingStatus::TechnicalError,
            lastStep: $state->last_step,
            technicalCode: $code,
            technicalMessage: $message,
            correlationId: $correlationId,
            // Tenant: indisponível sem detalhe OAuth/mTLS
            actionableCode: 'PLATFORM_UNAVAILABLE',
            actionableMessage: 'Integração SERPRO temporariamente indisponível. Tente novamente mais tarde.',
        );
    }

    private function clearActionable(TenantSerproOnboardingState $state): void
    {
        if ($state->actionable_code === null && $state->technical_code === null) {
            return;
        }
        $state->actionable_code = null;
        $state->actionable_message = null;
        $state->save();
    }

    private function sanitizeTechnicalMessage(string $message): string
    {
        $message = preg_replace('/\b[A-Za-z0-9+\/]{40,}={0,2}\b/', '[redacted]', $message) ?? $message;
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/consumer[_-]?secret[^\s]*/i', 'consumer_secret=[redacted]', $message) ?? $message;

        return mb_substr($message, 0, 500);
    }
}

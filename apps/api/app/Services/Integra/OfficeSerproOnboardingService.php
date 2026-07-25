<?php

namespace App\Services\Integra;

use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\CredentialStatus;
use App\Enums\FiscalControlModule;
use App\Enums\FiscalProfile;
use App\Enums\OfficeCredentialPurpose;
use App\Enums\OfficeSerproOnboardingStatus;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEnvironment;
use App\Enums\TermoAuthorizationState;
use App\Jobs\Fiscal\RecoverFiscalModuleJob;
use App\Jobs\Serpro\BeginOfficeFiscalReadinessJob;
use App\Jobs\Serpro\ProcessOfficeSerproOnboardingJob;
use App\Jobs\Serpro\SignTermoWithManagedA1Job;
use App\Models\Office;
use App\Models\OfficeCredential;
use App\Models\OfficeCredentialPurposeLink;
use App\Models\OfficeInstitutionalProfile;
use App\Models\OfficeSerproAuthorization;
use App\Models\OfficeSerproOnboardingState;
use App\Models\OfficeTechnicalConsent;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * State machine + enqueue de onboarding SERPRO automatizado (F-3.1).
 * Deriva estado de perfil/consentimento/A1 canônico + OfficeSerproAuthorization.
 */
final class OfficeSerproOnboardingService
{
    public function __construct(
        private readonly OfficeSerproAuthorizationService $authorizations,
        private readonly AuditLogger $audit,
    ) {}

    public function getOrCreateState(Office $office, SerproEnvironment $environment): OfficeSerproOnboardingState
    {
        $state = OfficeSerproOnboardingState::query()
            ->where('office_id', $office->id)
            ->where('environment', $environment->value)
            ->first();

        if ($state !== null) {
            return $state;
        }

        return OfficeSerproOnboardingState::query()->create([
            'office_id' => $office->id,
            'environment' => $environment,
            'status' => OfficeSerproOnboardingStatus::Incomplete,
            'last_transition_at' => now(),
        ]);
    }

    /**
     * Reavalia pré-requisitos e, se ready, enfileira job idempotente.
     *
     * @return array{
     *   state: OfficeSerproOnboardingState,
     *   enqueued: bool,
     *   prerequisites: array<string, bool>
     * }
     */
    public function evaluateAndMaybeEnqueue(
        Office $office,
        SerproEnvironment $environment,
        ?int $actorUserId = null,
        ?string $correlationId = null,
        bool $force = false,
    ): array {
        $state = $this->getOrCreateState($office, $environment);
        $this->ensureAuthorFromCanonicalSetup($office, $environment, $actorUserId);
        $prereq = $this->evaluatePrerequisites($office, $environment);
        $correlationId ??= (string) Str::uuid();

        if ($state->status === OfficeSerproOnboardingStatus::Revoked) {
            return ['state' => $state, 'enqueued' => false, 'prerequisites' => $prereq];
        }

        if (in_array($state->status, [OfficeSerproOnboardingStatus::Authorized, OfficeSerproOnboardingStatus::Ready], true) && ! $force) {
            $this->clearActionable($state);

            return ['state' => $state->refresh(), 'enqueued' => false, 'prerequisites' => $prereq];
        }

        if (! $prereq['complete']) {
            $this->transition(
                $state,
                OfficeSerproOnboardingStatus::Configuring,
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
            $this->authorizations->activateDevFixtureAuthorization($office, $environment, $actorUserId);

            $idempotencyKey = $this->buildIdempotencyKey($office, $environment, $prereq['fingerprint']);
            $this->transition(
                $state,
                OfficeSerproOnboardingStatus::Ready,
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
                OfficeSerproOnboardingStatus::Provisioning,
                OfficeSerproOnboardingStatus::Validating,
                OfficeSerproOnboardingStatus::Authorizing,
                OfficeSerproOnboardingStatus::LoadingProxyPowers,
                OfficeSerproOnboardingStatus::Syncing,
                OfficeSerproOnboardingStatus::Authorized,
                OfficeSerproOnboardingStatus::Ready,
            ], true)
            && ! $force
        ) {
            // Idempotência: já em andamento / autorizado
            return ['state' => $state, 'enqueued' => false, 'prerequisites' => $prereq];
        }

        $idempotencyKey = $this->buildIdempotencyKey($office, $environment, $prereq['fingerprint']);

        if (! $force && $state->idempotency_key === $idempotencyKey
            && in_array($state->status, [
                OfficeSerproOnboardingStatus::Validating,
                OfficeSerproOnboardingStatus::Authorizing,
                OfficeSerproOnboardingStatus::LoadingProxyPowers,
                OfficeSerproOnboardingStatus::Syncing,
            ], true)
        ) {
            return ['state' => $state, 'enqueued' => false, 'prerequisites' => $prereq];
        }

        // Pré-requisitos ok → validação auditável e enqueue.
        if ($state->status !== OfficeSerproOnboardingStatus::Validating
            || $state->idempotency_key !== $idempotencyKey
        ) {
            $this->transition(
                $state,
                OfficeSerproOnboardingStatus::Validating,
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
            OfficeSerproOnboardingStatus::Validating,
            lastStep: 'enqueued',
            correlationId: $correlationId,
            idempotencyKey: $idempotencyKey,
            readyAt: $state->ready_at ?? now(),
            provisioningStartedAt: now(),
            clearTechnical: true,
            clearActionable: true,
        );

        ProcessOfficeSerproOnboardingJob::dispatch(
            officeId: (int) $office->id,
            environment: $environment->value,
            idempotencyKey: $idempotencyKey,
            actorUserId: $actorUserId,
            correlationId: $correlationId,
        );

        $this->audit->record('serpro.onboarding.enqueue', 'SUCCESS', $state, [
            'environment' => $environment->value,
            'idempotency_key' => $idempotencyKey,
        ], $actorUserId, $office->id);

        return ['state' => $state->refresh(), 'enqueued' => true, 'prerequisites' => $prereq];
    }

    /**
     * Execução interna do job (com lock).
     */
    public function process(
        Office $office,
        SerproEnvironment $environment,
        string $idempotencyKey,
        ?int $actorUserId = null,
        ?string $correlationId = null,
    ): OfficeSerproOnboardingState {
        $lock = Cache::lock($this->lockKey($office, $environment), 120);
        if (! $lock->get()) {
            throw new RuntimeException('ONBOARDING_LOCK_BUSY');
        }

        try {
            $state = $this->getOrCreateState($office, $environment);

            if (
                $state->status === OfficeSerproOnboardingStatus::Ready
                && $state->idempotency_key === $idempotencyKey
            ) {
                return $state;
            }

            $this->ensureAuthorFromCanonicalSetup($office, $environment, $actorUserId);
            $prereq = $this->evaluatePrerequisites($office, $environment);
            if (! $prereq['complete']) {
                $this->transition(
                    $state,
                    OfficeSerproOnboardingStatus::Configuring,
                    lastStep: 'prerequisites',
                    actionableCode: $prereq['missing_code'],
                    actionableMessage: $prereq['missing_message'],
                    correlationId: $correlationId,
                );

                return $state->refresh();
            }

            $this->transition(
                $state,
                OfficeSerproOnboardingStatus::Authorizing,
                lastStep: 'authorizing',
                correlationId: $correlationId,
                idempotencyKey: $idempotencyKey,
                provisioningStartedAt: $state->provisioning_started_at ?? now(),
                clearTechnical: true,
                clearActionable: true,
            );

            $auth = $this->authorizations->getOrCreate($office, $environment);

            // 1) Draft do Termo se ausente
            $meta = is_array($auth->metadata) ? $auth->metadata : [];
            if (empty($meta['termo_draft_vault_object_id']) && $auth->termo_vault_object_id === null) {
                $this->setStep($state, 'termo_draft');
                $this->authorizations->generateTermoDraft($office, $environment, null, $actorUserId);
                $auth = $auth->refresh();
            }

            // 2) Assinar com A1 gerenciado de forma síncrona (mesmo path do job dedicado)
            if ($auth->termo_vault_object_id === null) {
                $this->setStep($state, 'termo_sign');
                if ($auth->certificate_mode === AuthorCertificateMode::ManagedA1) {
                    if (empty($meta['termo_draft_vault_object_id'])) {
                        $this->authorizations->generateTermoDraft($office, $environment, null, $actorUserId);
                        $auth = $auth->refresh();
                    }

                    SignTermoWithManagedA1Job::dispatchSync(
                        $office->id,
                        $environment->value,
                        $auth->id,
                        $actorUserId,
                        $correlationId,
                    );
                    $auth = $auth->refresh();
                    if ($auth->termo_vault_object_id === null) {
                        $this->transition(
                            $state,
                            OfficeSerproOnboardingStatus::ActionRequired,
                            lastStep: 'termo_sign',
                            actionableCode: 'UPLOAD_TERMO',
                            actionableMessage: 'Falha ao assinar o Termo com A1 gerenciado.',
                            correlationId: $correlationId,
                        );

                        return $state->refresh();
                    }
                } else {
                    $this->transition(
                        $state,
                        OfficeSerproOnboardingStatus::ActionRequired,
                        lastStep: 'termo_sign',
                        actionableCode: 'UPLOAD_TERMO',
                        actionableMessage: 'Envie o Termo de Autorização assinado ou use A1 gerenciado.',
                        correlationId: $correlationId,
                    );

                    return $state->refresh();
                }
            }

            // 3) Apoiar / token procurador
            $this->setStep($state, 'token_refresh');
            try {
                $auth = $this->authorizations->refreshProcuradorToken($office, $environment, $actorUserId);
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
                    OfficeSerproOnboardingStatus::ActionRequired,
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
                OfficeSerproOnboardingStatus::LoadingProxyPowers,
                lastStep: 'loading_proxy_powers',
                correlationId: $correlationId,
                idempotencyKey: $idempotencyKey,
                authorizedAt: now(),
                clearTechnical: true,
                clearActionable: true,
            );

            BeginOfficeFiscalReadinessJob::dispatch(
                (int) $office->id,
                $environment->value,
                $idempotencyKey,
                $actorUserId,
                $correlationId,
            );

            $this->audit->record('serpro.onboarding.authorization_ready', 'SUCCESS', $state, [
                'environment' => $environment->value,
                'authorization_status' => $auth->status->value,
            ], $actorUserId, $office->id);

            return $state->refresh();
        } finally {
            $lock->release();
        }
    }

    public function finalizeReadiness(
        Office $office,
        SerproEnvironment $environment,
        string $idempotencyKey,
        ?int $actorUserId = null,
        ?string $correlationId = null,
        ?string $batchId = null,
    ): OfficeSerproOnboardingState {
        $state = $this->getOrCreateState($office, $environment);
        if ($state->idempotency_key !== $idempotencyKey) {
            return $state;
        }

        $this->transition(
            $state,
            OfficeSerproOnboardingStatus::Syncing,
            lastStep: 'initial_collection',
            correlationId: $correlationId,
            clearTechnical: true,
            clearActionable: true,
        );

        foreach (FiscalControlModule::cases() as $module) {
            RecoverFiscalModuleJob::dispatch($module->value, (int) $office->id, (int) ($actorUserId ?? 0));
        }

        $metadata = is_array($state->metadata) ? $state->metadata : [];
        $metadata['procuracao_batch_id'] = $batchId;
        $metadata['initial_collection_queued_at'] = now()->toIso8601String();
        $state->metadata = $metadata;
        $state->save();

        $this->transition(
            $state,
            OfficeSerproOnboardingStatus::Ready,
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
        ], $actorUserId, (int) $office->id);

        return $state->refresh();
    }

    public function reactToProfileOrCredentialChange(
        Office $office,
        SerproEnvironment $environment,
        string $reason,
        ?int $actorUserId = null,
    ): OfficeSerproOnboardingState {
        $state = $this->getOrCreateState($office, $environment);

        if (in_array($state->status, [
            OfficeSerproOnboardingStatus::Authorized,
            OfficeSerproOnboardingStatus::Provisioning,
            OfficeSerproOnboardingStatus::Validating,
            OfficeSerproOnboardingStatus::Authorizing,
            OfficeSerproOnboardingStatus::LoadingProxyPowers,
            OfficeSerproOnboardingStatus::Syncing,
            OfficeSerproOnboardingStatus::Ready,
        ], true)) {
            $this->transition(
                $state,
                OfficeSerproOnboardingStatus::ActionRequired,
                lastStep: 'invalidate_'.$reason,
                actionableCode: 'REONBOARD_REQUIRED',
                actionableMessage: 'Perfil, consentimento ou A1 alterados — reonboarding necessário.',
            );

            try {
                $auth = $this->authorizations->getOrCreate($office, $environment);
                $this->authorizations->invalidateDerivedAuthorization(
                    $auth,
                    $office,
                    $environment,
                    reason: $reason,
                    actorUserId: $actorUserId,
                );
            } catch (Throwable) {
            }
        }

        return $this->evaluateAndMaybeEnqueue($office, $environment, $actorUserId)['state'];
    }

    /**
     * @return array{
     *   complete: bool,
     *   profile: bool,
     *   consent: bool,
     *   a1: bool,
     *   author: bool,
     *   missing_code: ?string,
     *   missing_message: ?string,
     *   fingerprint: string
     * }
     */
    public function evaluatePrerequisites(Office $office, SerproEnvironment $environment): array
    {
        $auth = OfficeSerproAuthorization::query()
            ->where('office_id', $office->id)
            ->where('environment', $environment->value)
            ->first();

        $profileOk = $this->hasInstitutionalProfile($office);
        $consentOk = $this->hasTechnicalConsent($office, $auth);
        $legacyManagedA1 = $auth !== null
            && $auth->author_pfx_vault_object_id !== null
            && $auth->certificate_mode === AuthorCertificateMode::ManagedA1;
        $canonicalA1 = $this->hasCanonicalOfficeA1($office);
        // External signature path: A1 not required if author configured (tenant signs offline)
        $externalOk = $auth !== null
            && $auth->certificate_mode === AuthorCertificateMode::ExternalSignature
            && $auth->author_identity !== ''
            && $auth->author_identity !== '00000000000000';

        $authorOk = $auth !== null
            && $auth->author_identity !== ''
            && $auth->author_identity !== '00000000000000';

        $credentialOk = $canonicalA1 || $legacyManagedA1 || $externalOk;

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
            $missingCode = 'A1_REQUIRED';
            $missingMessage = 'Envie o certificado e-CNPJ A1 do escritório.';
        }

        $complete = $profileOk && $consentOk && $authorOk && $credentialOk;
        $canonical = $this->activeCanonicalCredential((int) $office->id);
        $fingerprint = hash('sha256', implode('|', [
            (string) $office->id,
            $environment->value,
            $auth?->author_identity ?? '',
            $auth?->author_fingerprint_sha256 ?? $canonical?->fingerprint_sha256 ?? '',
            $auth?->certificate_mode?->value ?? '',
            $canonical?->fingerprint_sha256 ?? '',
            $complete ? '1' : '0',
        ]));

        return [
            'complete' => $complete,
            'profile' => $profileOk,
            'consent' => $consentOk,
            'a1' => $credentialOk,
            'author' => $authorOk,
            'missing_code' => $missingCode,
            'missing_message' => $missingMessage,
            'fingerprint' => $fingerprint,
        ];
    }

    /**
     * Deriva autor ManagedA1 do perfil + A1 canônico + consentimento técnico
     * (sem UI técnica SERPRO em /conta/escritorio).
     */
    public function ensureAuthorFromCanonicalSetup(
        Office $office,
        SerproEnvironment $environment,
        ?int $actorUserId = null,
    ): void {
        $profile = OfficeInstitutionalProfile::query()
            ->where('office_id', $office->id)
            ->first();

        if ($profile === null || ! $profile->isComplete()) {
            return;
        }

        if (! $this->hasCanonicalOfficeA1($office)) {
            return;
        }

        $auth = $this->authorizations->getOrCreate($office, $environment);
        if (! $this->hasTechnicalConsent($office, $auth)) {
            return;
        }

        $identity = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string) $profile->cnpj) ?? '');
        if (strlen($identity) !== 14) {
            return;
        }

        $placeholder = $auth->author_identity === '' || $auth->author_identity === '00000000000000';
        $needsAuthorSync = $placeholder
            || $auth->author_identity !== $identity
            || $auth->certificate_mode !== AuthorCertificateMode::ManagedA1;

        if ($needsAuthorSync) {
            $auth = $this->authorizations->configureAuthor(
                $office,
                $environment,
                AuthorIdentityType::Cnpj,
                $identity,
                $profile->legal_name,
                AuthorCertificateMode::ManagedA1,
                $actorUserId,
            );
        }

        $canonical = $this->activeCanonicalCredential((int) $office->id);
        $dirty = false;
        if (! $auth->managed_a1_consent) {
            $auth->managed_a1_consent = true;
            $auth->managed_a1_consented_at = now();
            $dirty = true;
        }
        if ($canonical !== null) {
            if ($auth->author_fingerprint_sha256 !== $canonical->fingerprint_sha256) {
                $auth->author_fingerprint_sha256 = $canonical->fingerprint_sha256;
                $dirty = true;
            }
            if ($auth->author_cert_valid_from?->toIso8601String() !== $canonical->valid_from?->toIso8601String()) {
                $auth->author_cert_valid_from = $canonical->valid_from;
                $dirty = true;
            }
            if ($auth->author_cert_valid_to?->toIso8601String() !== $canonical->valid_to?->toIso8601String()) {
                $auth->author_cert_valid_to = $canonical->valid_to;
                $dirty = true;
            }
        }
        if ($dirty) {
            $auth->save();
        }
    }

    private function hasCanonicalOfficeA1(Office $office): bool
    {
        return $this->activeCanonicalCredential((int) $office->id) !== null
            || $this->activeSerproTermSigningCredential((int) $office->id) !== null;
    }

    private function activeCanonicalCredential(int $officeId): ?OfficeCredential
    {
        return OfficeCredential::query()
            ->where('office_id', $officeId)
            ->where('purpose', OfficeCredentialPurpose::CanonicalECnpjA1->value)
            ->where('status', CredentialStatus::Active)
            ->first();
    }

    private function activeSerproTermSigningCredential(int $officeId): ?OfficeCredential
    {
        $link = OfficeCredentialPurposeLink::query()
            ->where('office_id', $officeId)
            ->where('purpose', OfficeCredentialPurpose::SerproTermSigning->value)
            ->where('status', CredentialStatus::Active)
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->first();

        if ($link === null) {
            return null;
        }

        $credential = $link->credential;
        if ($credential === null || ! $credential->status->isUsable()) {
            return null;
        }

        return $credential;
    }

    private function hasInstitutionalProfile(Office $office): bool
    {
        $profile = OfficeInstitutionalProfile::query()
            ->where('office_id', $office->id)
            ->first();

        if ($profile !== null) {
            return $profile->isComplete();
        }

        // Fallback legacy: office name + author identity (pré-backfill A-1.1).
        if (trim((string) $office->name) === '') {
            return false;
        }

        $auth = OfficeSerproAuthorization::query()->where('office_id', $office->id)->first();

        return $auth !== null
            && $auth->author_identity !== ''
            && $auth->author_identity !== '00000000000000';
    }

    private function hasTechnicalConsent(Office $office, ?OfficeSerproAuthorization $auth): bool
    {
        $consent = OfficeTechnicalConsent::query()
            ->where('office_id', $office->id)
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->first();

        if ($consent !== null) {
            return $consent->isActive();
        }

        if ($auth === null) {
            return false;
        }

        // Compat: managed A1 consent flag ou autor externo configurado.
        if ($auth->certificate_mode === AuthorCertificateMode::ManagedA1) {
            return (bool) $auth->managed_a1_consent;
        }

        return $auth->author_identity !== '' && $auth->author_identity !== '00000000000000';
    }

    private function buildIdempotencyKey(Office $office, SerproEnvironment $environment, string $fingerprint): string
    {
        return substr(hash('sha256', 'onboard|'.$office->id.'|'.$environment->value.'|'.$fingerprint), 0, 64);
    }

    private function lockKey(Office $office, SerproEnvironment $environment): string
    {
        return sprintf('serpro:onboarding:%d:%s', $office->id, $environment->value);
    }

    private function transition(
        OfficeSerproOnboardingState $state,
        OfficeSerproOnboardingStatus $to,
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

    private function setStep(OfficeSerproOnboardingState $state, string $step): void
    {
        $state->last_step = $step;
        $state->last_transition_at = now();
        $state->save();
    }

    private function markTechnicalError(
        OfficeSerproOnboardingState $state,
        string $code,
        string $message,
        ?string $correlationId,
    ): void {
        $this->transition(
            $state,
            OfficeSerproOnboardingStatus::TechnicalError,
            lastStep: $state->last_step,
            technicalCode: $code,
            technicalMessage: $message,
            correlationId: $correlationId,
            // Tenant: indisponível sem detalhe OAuth/mTLS
            actionableCode: 'PLATFORM_UNAVAILABLE',
            actionableMessage: 'Integração SERPRO temporariamente indisponível. Tente novamente mais tarde.',
        );
    }

    private function clearActionable(OfficeSerproOnboardingState $state): void
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

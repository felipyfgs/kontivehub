<?php

namespace App\Services\Serpro;

use App\DTO\Serpro\ProductionOnboardingInput;
use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\SerproCredentialVersionStatus;
use App\Enums\SerproEnvironment;
use App\Enums\SerproProductionOnboardingStatus;
use App\Enums\SerproProductionOnboardingStep;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Models\SerproAuthorizationConsent;
use App\Models\SerproCredentialVersion;
use App\Models\SerproProductionOnboarding;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Certificates\ContractorPfxValidator;
use App\Services\Integra\OfficeSerproAuthorizationService;
use App\Support\FeatureFlags;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class SerproProductionOnboardingService
{
    public function __construct(
        private readonly ContractorPfxValidator $pfxValidator,
        private readonly SerproCredentialVersionService $credentials,
        private readonly SerproRolloutApprovalService $rollouts,
        private readonly OfficeSerproAuthorizationService $authorizations,
        private readonly SerproInitialMailboxSyncDispatcher $mailboxSync,
        private readonly AuditLogger $audit,
    ) {}

    public function latestForOffice(Office $office): ?SerproProductionOnboarding
    {
        return SerproProductionOnboarding::query()
            ->where('office_id', $office->id)
            ->where('environment', SerproEnvironment::Production->value)
            ->orderByDesc('id')
            ->first();
    }

    public function activate(Office $office, User $actor, ProductionOnboardingInput $input): SerproProductionOnboarding
    {
        if (! FeatureFlags::isSerproProductionOnboardingEnabled((int) $office->id)) {
            throw new RuntimeException('Ativação simplificada SERPRO está desabilitada para este tenant.');
        }

        if (! $input->consentGranted) {
            throw new RuntimeException('Consentimento explícito é obrigatório.');
        }

        $lock = Cache::lock($this->lockKey($office, $input->idempotencyKey), 300);
        if (! $lock->get()) {
            throw new RuntimeException('Onboarding SERPRO já está em processamento para esta chave.');
        }

        try {
            $onboarding = $this->getOrCreateOnboarding($office, $actor, $input);

            if ($onboarding->status === SerproProductionOnboardingStatus::Active
                || $onboarding->status === SerproProductionOnboardingStatus::ActiveSyncPending
                || $onboarding->status === SerproProductionOnboardingStatus::ActionRequired
            ) {
                return $onboarding;
            }

            $onboarding->forceFill([
                'status' => SerproProductionOnboardingStatus::Running,
                'started_at' => $onboarding->started_at ?? now(),
                'error_code' => null,
                'error_message' => null,
            ])->save();

            $meta = $this->validateInput($onboarding, $input);
            $version = $this->storeOrReuseCredential($onboarding, $input, $meta, $actor);
            $version = $this->verifyAndTest($onboarding, $version, $actor);
            $version = $this->confirmAndCutover($onboarding, $version, $actor, $office);
            $authorization = $this->activateAuthorization($onboarding, $office, $actor, $version);
            $this->queueReadSync($onboarding, $office, $authorization, $actor);

            $onboarding->markStepCompleted(SerproProductionOnboardingStep::Completed);
            if ($onboarding->status !== SerproProductionOnboardingStatus::ActionRequired) {
                $onboarding->status = $onboarding->initial_mailbox_run_id !== null
                    ? SerproProductionOnboardingStatus::ActiveSyncPending
                    : SerproProductionOnboardingStatus::Active;
            }
            $onboarding->current_step = SerproProductionOnboardingStep::Completed;
            $onboarding->finished_at = now();
            $onboarding->save();

            $this->audit->record('serpro.production_onboarding.complete', 'SUCCESS', $onboarding, [
                'environment' => SerproEnvironment::Production->value,
                'credential_version_id' => $version->id,
                'authorization_id' => $authorization->id,
                'status' => $onboarding->status->value,
            ], $actor->id, $office->id);

            return $onboarding->refresh();
        } catch (Throwable $e) {
            if (isset($onboarding) && $onboarding instanceof SerproProductionOnboarding) {
                $this->fail($onboarding, $this->classifyError($e), $this->sanitize($e->getMessage()));
            }

            throw $e;
        } finally {
            unset($input);
            $lock->release();
        }
    }

    private function getOrCreateOnboarding(
        Office $office,
        User $actor,
        ProductionOnboardingInput $input,
    ): SerproProductionOnboarding {
        $existing = SerproProductionOnboarding::query()
            ->where('office_id', $office->id)
            ->where('environment', SerproEnvironment::Production->value)
            ->where('idempotency_key', $input->idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return SerproProductionOnboarding::query()->create([
            'office_id' => $office->id,
            'actor_user_id' => $actor->id,
            'environment' => SerproEnvironment::Production,
            'idempotency_key' => $input->idempotencyKey,
            'status' => SerproProductionOnboardingStatus::Pending,
            'current_step' => SerproProductionOnboardingStep::ValidateInput,
            'completed_steps' => [],
            'consent_version' => $this->consentVersion(),
            'consent_text_sha256' => $this->consentTextHash(),
            'consented_at' => now(),
            'correlation_id' => (string) Str::uuid(),
            'consumer_key_hint' => $this->hint($input->consumerKey),
            'metadata' => [
                'resume_window_minutes' => (int) config('serpro.production_onboarding.resume_window_minutes', 30),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateInput(SerproProductionOnboarding $onboarding, ProductionOnboardingInput $input): array
    {
        $this->setStep($onboarding, SerproProductionOnboardingStep::ValidateInput);

        $meta = $this->pfxValidator->validate(
            $input->pfxBinary,
            $input->certificatePassword,
            null,
            (int) config('serpro.contractor_pfx.min_horizon_days', 7),
            (bool) config('serpro.contractor_pfx.require_chain', false),
        );

        $safe = $this->pfxValidator->toSanitizedMetadata($meta);
        $onboarding->forceFill([
            'consumer_key_hint' => $this->hint($input->consumerKey),
            'certificate_fingerprint_sha256' => (string) $meta['fingerprint_sha256'],
            'contractor_cnpj_masked' => (string) ($safe['cnpj_masked'] ?? '****'),
            'certificate_valid_to' => $meta['valid_to'],
            'metadata' => $this->mergeMetadata($onboarding, ['certificate' => $safe]),
        ]);
        $this->completeStep($onboarding, SerproProductionOnboardingStep::ValidateInput);

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function storeOrReuseCredential(
        SerproProductionOnboarding $onboarding,
        ProductionOnboardingInput $input,
        array $meta,
        User $actor,
    ): SerproCredentialVersion {
        $this->setStep($onboarding, SerproProductionOnboardingStep::StorePending);

        if ($onboarding->serpro_credential_version_id !== null) {
            return SerproCredentialVersion::query()->findOrFail($onboarding->serpro_credential_version_id);
        }

        $active = SerproCredentialVersion::query()
            ->where('environment', SerproEnvironment::Production->value)
            ->where('status', SerproCredentialVersionStatus::Active->value)
            ->where('fingerprint_sha256', (string) $meta['fingerprint_sha256'])
            ->where('consumer_key_hint', $this->hint($input->consumerKey))
            ->first();

        if ($active !== null) {
            $onboarding->forceFill([
                'serpro_credential_version_id' => $active->id,
                'metadata' => $this->mergeMetadata($onboarding, ['credential_reused' => true]),
            ]);
            $this->completeStep($onboarding, SerproProductionOnboardingStep::StorePending);

            return $active;
        }

        $version = $this->credentials->registerPending(
            environment: SerproEnvironment::Production,
            pfxBinary: $input->pfxBinary,
            password: $input->certificatePassword,
            consumerKey: $input->consumerKey,
            consumerSecret: $input->consumerSecret,
            actorUserId: $actor->id,
            expectedCnpj: (string) $meta['cnpj'],
            notes: 'Criada pelo onboarding produtivo simplificado #'.$onboarding->id,
        );

        $onboarding->serpro_credential_version_id = $version->id;
        $this->completeStep($onboarding, SerproProductionOnboardingStep::StorePending);

        return $version->refresh();
    }

    private function verifyAndTest(
        SerproProductionOnboarding $onboarding,
        SerproCredentialVersion $version,
        User $actor,
    ): SerproCredentialVersion {
        if ($version->status === SerproCredentialVersionStatus::Active) {
            $this->completeStep($onboarding, SerproProductionOnboardingStep::VerifyVault);
            $this->completeStep($onboarding, SerproProductionOnboardingStep::TestOauth);

            return $version;
        }

        $this->setStep($onboarding, SerproProductionOnboardingStep::VerifyVault);
        if ($version->status === SerproCredentialVersionStatus::Pending) {
            $version = $this->credentials->verifyPending($version, $actor->id);
        }
        $this->completeStep($onboarding, SerproProductionOnboardingStep::VerifyVault);

        $this->setStep($onboarding, SerproProductionOnboardingStep::TestOauth);
        $this->credentials->testConnection($version, $actor->id);
        $this->completeStep($onboarding, SerproProductionOnboardingStep::TestOauth);

        return $version->refresh();
    }

    private function confirmAndCutover(
        SerproProductionOnboarding $onboarding,
        SerproCredentialVersion $version,
        User $actor,
        Office $office,
    ): SerproCredentialVersion {
        if ($version->status === SerproCredentialVersionStatus::Active) {
            $this->completeStep($onboarding, SerproProductionOnboardingStep::ConfirmCutover);

            return $version;
        }

        $this->setStep($onboarding, SerproProductionOnboardingStep::ConfirmCutover);

        if ($onboarding->serpro_rollout_approval_id === null) {
            $approval = $this->rollouts->request(
                action: SerproRolloutApprovalService::ACTION_CREDENTIAL_CUTOVER,
                subjectType: 'CREDENTIAL_VERSION',
                subjectId: (int) $version->id,
                reason: 'Onboarding produtivo simplificado SERPRO #'.$onboarding->id,
                requestedByUserId: (int) $actor->id,
                environment: SerproEnvironment::Production,
                officeId: (int) $office->id,
                context: [
                    'onboarding_id' => $onboarding->id,
                    'consent_version' => $onboarding->consent_version,
                    'consent_text_sha256' => $onboarding->consent_text_sha256,
                ],
                ttlHours: 1,
                changeWindowStart: CarbonImmutable::now()->subMinute(),
                changeWindowEnd: CarbonImmutable::now()->addHour(),
                fromHttp: true,
            );

            $this->rollouts->approve(
                $approval,
                (int) $actor->id,
                passwordRecentlyConfirmed: true,
                reason: 'Confirmação sensível consumida pelo onboarding produtivo simplificado #'.$onboarding->id,
                confirmationPhrase: $this->rollouts->expectedConfirmationPhrase(
                    SerproRolloutApprovalService::ACTION_CREDENTIAL_CUTOVER,
                ),
                changeWindowStart: CarbonImmutable::now()->subMinute(),
                changeWindowEnd: CarbonImmutable::now()->addHour(),
                fromHttp: true,
            );

            $onboarding->serpro_rollout_approval_id = $approval->id;
            $onboarding->save();
        }

        $active = $this->credentials->cutover(
            $version->fresh(),
            actorUserId: $actor->id,
            approvalId: (int) $onboarding->serpro_rollout_approval_id,
        );
        $this->completeStep($onboarding, SerproProductionOnboardingStep::ConfirmCutover);

        return $active;
    }

    private function activateAuthorization(
        SerproProductionOnboarding $onboarding,
        Office $office,
        User $actor,
        SerproCredentialVersion $version,
    ): OfficeSerproAuthorization {
        $this->setStep($onboarding, SerproProductionOnboardingStep::ActivateAuthorization);

        $auth = $this->authorizations->getOrCreate($office, SerproEnvironment::Production);
        if ($auth->author_identity === '' || $auth->author_identity === '00000000000000') {
            $this->authorizations->configureAuthor(
                office: $office,
                environment: SerproEnvironment::Production,
                identityType: AuthorIdentityType::Cnpj,
                identity: (string) $version->contractor_cnpj,
                authorName: $version->subject_name,
                mode: AuthorCertificateMode::ExternalSignature,
                actorUserId: $actor->id,
            );
            $auth = $auth->refresh();
        }

        SerproAuthorizationConsent::query()->create([
            'office_id' => $office->id,
            'office_serpro_authorization_id' => $auth->id,
            'consent_type' => SerproAuthorizationConsent::TYPE_PRODUCTION_ONBOARDING,
            'version_code' => $onboarding->consent_version,
            'actor_user_id' => $actor->id,
            'consented_at' => $onboarding->consented_at,
            'payload_sha256' => hash('sha256', implode('|', [
                $onboarding->consent_text_sha256,
                $onboarding->certificate_fingerprint_sha256 ?? '',
                (string) $office->id,
                (string) $actor->id,
            ])),
            'metadata' => [
                'onboarding_id' => $onboarding->id,
                'credential_version_id' => $version->id,
                'environment' => SerproEnvironment::Production->value,
            ],
        ]);

        $onboarding->office_serpro_authorization_id = $auth->id;
        $this->completeStep($onboarding, SerproProductionOnboardingStep::ActivateAuthorization);

        return $auth->refresh();
    }

    private function queueReadSync(
        SerproProductionOnboarding $onboarding,
        Office $office,
        OfficeSerproAuthorization $authorization,
        User $actor,
    ): void {
        $this->setStep($onboarding, SerproProductionOnboardingStep::QueueReadSync);

        $result = $this->mailboxSync->dispatchIfAllowed(
            office: $office,
            authorization: $authorization,
            idempotencyKey: $onboarding->idempotency_key,
            actorUserId: $actor->id,
            correlationId: $onboarding->correlation_id,
        );

        if ($result['run'] !== null) {
            $onboarding->initial_mailbox_run_id = $result['run']->id;
            $onboarding->required_actions = [];
            $this->completeStep($onboarding, SerproProductionOnboardingStep::QueueReadSync);

            return;
        }

        $onboarding->status = SerproProductionOnboardingStatus::ActionRequired;
        $onboarding->required_actions = [[
            'code' => $result['code'],
            'message' => $result['message'],
        ]];
        $onboarding->error_code = $result['code'];
        $onboarding->error_message = $result['message'];
        $this->completeStep($onboarding, SerproProductionOnboardingStep::QueueReadSync);
    }

    private function setStep(SerproProductionOnboarding $onboarding, SerproProductionOnboardingStep $step): void
    {
        $onboarding->current_step = $step;
        $onboarding->save();
    }

    private function completeStep(SerproProductionOnboarding $onboarding, SerproProductionOnboardingStep $step): void
    {
        $onboarding->markStepCompleted($step);
        $onboarding->save();
    }

    private function fail(SerproProductionOnboarding $onboarding, string $code, string $message): void
    {
        $onboarding->forceFill([
            'status' => SerproProductionOnboardingStatus::Failed,
            'error_code' => $code,
            'error_message' => mb_substr($message, 0, 500),
            'finished_at' => now(),
        ])->save();

        if ($onboarding->serpro_credential_version_id !== null) {
            $version = SerproCredentialVersion::query()->find($onboarding->serpro_credential_version_id);
            if ($version !== null && in_array($version->status, [
                SerproCredentialVersionStatus::Pending,
                SerproCredentialVersionStatus::Verified,
            ], true)) {
                $version->forceFill([
                    'status' => SerproCredentialVersionStatus::Retired,
                    'retired_at' => now(),
                    'notes' => trim((string) $version->notes."\nRetirada após falha sanitizada do onboarding #{$onboarding->id}."),
                ])->save();
            }
        }

        $this->audit->record('serpro.production_onboarding.failed', 'FAILED', $onboarding, [
            'code' => $code,
            'step' => $onboarding->current_step?->value,
            'message' => $message,
        ], (int) $onboarding->actor_user_id, (int) $onboarding->office_id);
    }

    private function classifyError(Throwable $e): string
    {
        $step = request()?->attributes->get('serpro_onboarding_step');
        $message = strtolower($e->getMessage());

        return match (true) {
            str_contains($message, 'pfx') || str_contains($message, 'certificado') || str_contains($message, 'cnpj') => 'CERTIFICATE_INVALID',
            str_contains($message, 'cofre') || str_contains($message, 'vault') => 'VAULT_ERROR',
            str_contains($message, 'oauth') || str_contains($message, 'mtls') => 'OAUTH_FAILED',
            str_contains($message, 'aprovação') || str_contains($message, 'owner') => 'APPROVAL_REQUIRED',
            default => is_string($step) && $step !== '' ? strtoupper($step).'_FAILED' : 'ONBOARDING_FAILED',
        };
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;
        $message = preg_replace('/\b[A-Za-z0-9+\/]{40,}={0,2}\b/', '[redacted]', $message) ?? $message;
        $message = preg_replace('/(consumer[_-]?secret|password|senha|pfx|jwt|xml|token)[^,;. ]*/i', '$1=[redacted]', $message) ?? $message;

        return mb_substr($message, 0, 500);
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function mergeMetadata(SerproProductionOnboarding $onboarding, array $patch): array
    {
        $meta = is_array($onboarding->metadata) ? $onboarding->metadata : [];

        return array_replace_recursive($meta, $patch);
    }

    private function hint(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return strlen($value) <= 4 ? str_repeat('*', strlen($value)) : '****'.substr($value, -4);
    }

    private function consentVersion(): string
    {
        return (string) config('serpro.production_onboarding.consent_version', SerproAuthorizationConsent::VERSION_PRODUCTION_ONBOARDING_V1);
    }

    private function consentTextHash(): string
    {
        return hash('sha256', (string) config('serpro.production_onboarding.consent_text', ''));
    }

    private function lockKey(Office $office, string $idempotencyKey): string
    {
        return 'serpro:prod-onboarding:'.$office->id.':'.hash('sha256', $idempotencyKey);
    }
}

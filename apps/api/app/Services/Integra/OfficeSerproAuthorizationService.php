<?php

namespace App\Services\Integra;

use App\Contracts\AutenticarProcuradorClient;
use App\Contracts\PfxReaderInterface;
use App\Contracts\SecureObjectStore;
use App\Contracts\SerproContractAuthenticator;
use App\Domain\BrazilianTaxId;
use App\DTO\Serpro\ProcuradorAuthRequest;
use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\FiscalProfile;
use App\Enums\SecureObjectPurpose;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproDataSegregationClass;
use App\Enums\SerproEnvironment;
use App\Enums\TaxProxyPowerStatus;
use App\Enums\TermoAuthorizationState;
use App\Enums\TermRePresentationStrategy;
use App\Jobs\Serpro\SignTermoWithManagedA1Job;
use App\Models\ClientProcuracaoSnapshot;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Models\OfficeSerproAuthorizationEvent;
use App\Models\SerproAuthorizationConsent;
use App\Models\SerproTermVersion;
use App\Models\TaxProxyPower;
use App\Services\Audit\AuditLogger;
use App\Services\Serpro\SerproContractService;
use App\Services\Serpro\SerproOperationAttemptStore;
use App\Services\Serpro\SerproProductionOnboardingGuard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Onboarding Autor do Pedido / Termo / token do procurador (tenant-scoped).
 * Nunca retorna XML, PFX ou tokens nas APIs públicas (download de draft é endpoint dedicado).
 */
final class OfficeSerproAuthorizationService
{
    public function __construct(
        private readonly SecureObjectStore $store,
        private readonly PfxReaderInterface $pfxReader,
        private readonly TermoXmlValidator $termoValidator,
        private readonly TermoAutorizacaoGenerator $termoGenerator,
        private readonly SerproContractService $contracts,
        private readonly SerproContractAuthenticator $authenticator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Evita ciclo no bootstrap com o driver real:
     * SerproOperationService -> IntegraEligibilityService -> esta classe ->
     * HttpAutenticarProcuradorClient -> SerproOperationService.
     */
    private function procuradorClient(): AutenticarProcuradorClient
    {
        return app(AutenticarProcuradorClient::class);
    }

    public function getOrCreate(Office $office, SerproEnvironment $environment): OfficeSerproAuthorization
    {
        $auth = OfficeSerproAuthorization::query()
            ->where('office_id', $office->id)
            ->where('environment', $environment->value)
            ->first();

        if ($auth !== null) {
            return $auth;
        }

        return OfficeSerproAuthorization::query()->create([
            'office_id' => $office->id,
            'environment' => $environment,
            'status' => SerproAuthorizationStatus::Draft,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '00000000000000',
            'certificate_mode' => AuthorCertificateMode::ExternalSignature,
            'termo_authorization_state' => TermoAuthorizationState::Draft,
        ]);
    }

    public function configureAuthor(
        Office $office,
        SerproEnvironment $environment,
        AuthorIdentityType $identityType,
        string $identity,
        ?string $authorName = null,
        AuthorCertificateMode $mode = AuthorCertificateMode::ExternalSignature,
        ?int $actorUserId = null,
    ): OfficeSerproAuthorization {
        $this->assertOfficeEligibleForEnvironment($office, $environment);
        $identity = $this->normalizeIdentity($identity);
        $this->assertIdentity($identityType, $identity);

        $auth = $this->getOrCreate($office, $environment);
        $from = $auth->status;
        $identityChanged = $auth->author_identity !== $identity
            && $auth->author_identity !== ''
            && $auth->author_identity !== '00000000000000';
        $modeChanged = $auth->certificate_mode !== $mode;

        $auth->author_identity_type = $identityType;
        $auth->author_identity = $identity;
        $auth->author_name = $authorName;
        $auth->certificate_mode = $mode;

        if ($auth->status === SerproAuthorizationStatus::Draft) {
            $auth->status = SerproAuthorizationStatus::PendingTerm;
        }

        if ($mode === AuthorCertificateMode::InteractiveA3) {
            $auth->managed_a1_consent = false;
        }

        $auth->save();

        if ($identityChanged || $modeChanged) {
            $this->invalidateDerivedAuthorization(
                $auth,
                $office,
                $environment,
                reason: 'author_changed',
                actorUserId: $actorUserId,
            );
            $auth = $auth->refresh();
        }

        $this->recordEvent($auth, $from, $auth->status, 'author.configure', 'Autor configurado.', $actorUserId);

        $this->audit->record('serpro.authorization.author_configure', 'SUCCESS', $auth, [
            'environment' => $environment->value,
            'certificate_mode' => $mode->value,
            'identity_type' => $identityType->value,
        ], $actorUserId, $office->id);

        return $auth->refresh();
    }

    /**
     * Gera draft canônico não assinado e armazena no vault (fluxo externo A1/A3).
     *
     * @return array{auth: OfficeSerproAuthorization, draft_sha256: string}
     */
    public function generateTermoDraft(
        Office $office,
        SerproEnvironment $environment,
        CarbonImmutable|string|null $vigencia = null,
        ?int $actorUserId = null,
    ): array {
        $auth = $this->getOrCreate($office, $environment);

        if ($auth->author_identity === '' || $auth->author_identity === '00000000000000') {
            throw new RuntimeException('Configure a identidade do Autor do Pedido antes do draft.');
        }

        [$destinationCnpj, $destinationName] = $this->resolveDestination($environment);
        $authorTipo = $auth->author_identity_type === AuthorIdentityType::Cpf ? 'PF' : 'PJ';
        $authorName = $auth->author_name ?: 'Autor do Pedido';
        $dataAssinatura = CarbonImmutable::now('America/Sao_Paulo');
        $vigenciaDate = $vigencia !== null
            ? ($vigencia instanceof CarbonImmutable ? $vigencia : CarbonImmutable::parse((string) $vigencia))
            : $dataAssinatura->addYear();

        $xml = $this->termoGenerator->generateUnsigned(
            destinationCnpj: $destinationCnpj,
            destinationName: $destinationName,
            authorIdentity: $auth->author_identity,
            authorName: $authorName,
            authorTipo: $authorTipo,
            dataAssinatura: $dataAssinatura,
            vigencia: $vigenciaDate,
        );
        $sha = hash('sha256', $xml);

        $aad = SecureObjectPurpose::SerproTermoXml->aadBase([
            'office_id' => $office->id,
            'environment' => $environment->value,
            'kind' => 'draft',
            'sha256' => $sha,
            'author_identity' => $auth->author_identity,
        ]);
        $objectId = $this->store->put($xml, $aad);
        unset($xml);

        $meta = is_array($auth->metadata) ? $auth->metadata : [];
        $previousDraft = $meta['termo_draft_vault_object_id'] ?? null;
        $meta['termo_draft_vault_object_id'] = $objectId;
        $meta['termo_draft_sha256'] = $sha;
        $meta['termo_draft_generated_at'] = now()->toIso8601String();
        $meta['termo_draft_schema_version'] = TermoAutorizacaoGenerator::SCHEMA_VERSION;

        $from = $auth->status;
        $auth->metadata = $meta;
        $auth->termo_authorization_state = TermoAuthorizationState::Draft;
        if ($auth->status === SerproAuthorizationStatus::Draft) {
            $auth->status = SerproAuthorizationStatus::PendingTerm;
        }
        $auth->save();

        if (is_string($previousDraft) && $previousDraft !== '' && $previousDraft !== $objectId) {
            try {
                $this->store->delete($previousDraft);
            } catch (Throwable) {
            }
        }

        $this->recordEvent($auth, $from, $auth->status, 'termo.draft_generate', 'Draft do Termo gerado.', $actorUserId, [
            'draft_sha256' => $sha,
        ]);
        $this->audit->record('serpro.authorization.termo_draft', 'SUCCESS', $auth, [
            'draft_sha256' => $sha,
            'environment' => $environment->value,
        ], $actorUserId, $office->id);

        return ['auth' => $auth->refresh(), 'draft_sha256' => $sha];
    }

    /**
     * Retorna XML do draft para download protegido (caller deve ser ADMIN autenticado).
     */
    public function getTermoDraftXml(Office $office, SerproEnvironment $environment): string
    {
        $auth = $this->getOrCreate($office, $environment);
        $meta = is_array($auth->metadata) ? $auth->metadata : [];
        $objectId = $meta['termo_draft_vault_object_id'] ?? null;
        $sha = $meta['termo_draft_sha256'] ?? null;
        if (! is_string($objectId) || $objectId === '' || ! is_string($sha) || $sha === '') {
            throw new RuntimeException('Draft do Termo não encontrado. Gere o draft antes do download.');
        }

        $aad = SecureObjectPurpose::SerproTermoXml->aadBase([
            'office_id' => $office->id,
            'environment' => $environment->value,
            'kind' => 'draft',
            'sha256' => $sha,
            'author_identity' => $auth->author_identity,
        ]);

        return $this->store->get($objectId, $aad);
    }

    public function uploadTermo(
        Office $office,
        SerproEnvironment $environment,
        string $termoXml,
        ?int $actorUserId = null,
    ): OfficeSerproAuthorization {
        $this->assertOfficeEligibleForEnvironment($office, $environment);
        $auth = $this->getOrCreate($office, $environment);

        if ($auth->author_identity === '' || $auth->author_identity === '00000000000000') {
            throw new RuntimeException('Configure a identidade do Autor do Pedido antes do Termo.');
        }

        [$destination] = $this->resolveDestination($environment);

        $validation = $this->termoValidator->validate(
            $termoXml,
            $auth->author_identity,
            $destination,
        );

        if (! $validation->valid) {
            $auth->termo_authorization_state = TermoAuthorizationState::tryFrom((string) ($validation->authorizationState ?? ''))
                ?? TermoAuthorizationState::Rejected;
            $auth->last_validation_result = $validation->errorCode ?? 'TERM_REJECTED';
            $auth->last_validation_message = mb_substr(
                $validation->errorMessage ?? 'Termo inválido.',
                0,
                500,
            );
            $auth->last_validated_at = now();
            $auth->save();

            $this->audit->record('serpro.authorization.termo_upload', 'FAILED', $auth, [
                'error_code' => $validation->errorCode,
                'message' => $validation->errorMessage,
            ], $actorUserId, $office->id);

            throw new RuntimeException($validation->errorMessage ?? 'Termo inválido.');
        }

        return DB::transaction(function () use ($office, $environment, $termoXml, $actorUserId, $auth, $validation) {
            // Invalidar token/cache/poderes da versão anterior antes de promover o novo Termo.
            $this->invalidateDerivedAuthorization(
                $auth,
                $office,
                $environment,
                reason: 'termo_replaced',
                actorUserId: $actorUserId,
                keepTermo: true,
            );

            $aad = SecureObjectPurpose::SerproTermoXml->aadBase([
                'office_id' => $office->id,
                'environment' => $environment->value,
                'kind' => 'signed',
                'sha256' => $validation->sha256,
                'author_identity' => $auth->author_identity,
            ]);

            $objectId = $this->store->put($termoXml, $aad);

            $from = $auth->status;
            $auth->termo_vault_object_id = $objectId;
            $auth->termo_sha256 = $validation->sha256;
            $auth->termo_valid_from = $validation->validFrom;
            $auth->termo_valid_to = $validation->validTo;
            $auth->termo_destination_cnpj = $validation->destinationCnpj;
            $auth->termo_signed_by = $validation->signedBy;
            $auth->termo_uploaded_at = now();
            $auth->termo_authorization_state = TermoAuthorizationState::LocalValidated;
            $auth->last_validation_result = 'TERM_VALID';
            $auth->last_validation_message = 'Termo validado localmente (LOCAL_VALIDATED ≠ SERPRO_ACCEPTED).';
            $auth->last_validated_at = now();
            $auth->status = SerproAuthorizationStatus::TermValid;
            $auth->action_required_reason = null;

            // Limpar draft após upload assinado.
            $meta = is_array($auth->metadata) ? $auth->metadata : [];
            unset($meta['termo_draft_vault_object_id'], $meta['termo_draft_sha256'], $meta['termo_draft_generated_at']);
            $auth->metadata = $meta;
            $auth->save();

            $versionNumber = (int) SerproTermVersion::query()
                ->where('office_serpro_authorization_id', $auth->id)
                ->max('version_number') + 1;

            // Revogar versões anteriores (retenção de evidência).
            SerproTermVersion::query()
                ->where('office_serpro_authorization_id', $auth->id)
                ->whereIn('status', ['LOCAL_VALIDATED', 'SIGNED', 'SERPRO_ACCEPTED', 'ACTIVE'])
                ->update(['status' => TermoAuthorizationState::Revoked->value]);

            SerproTermVersion::query()->create([
                'office_id' => $office->id,
                'office_serpro_authorization_id' => $auth->id,
                'environment' => $environment->value,
                'version_number' => $versionNumber,
                'status' => TermoAuthorizationState::LocalValidated->value,
                'author_identity' => $auth->author_identity,
                'destination_cnpj' => $validation->destinationCnpj,
                'termo_sha256' => $validation->sha256,
                'termo_vault_object_id' => $objectId,
                'signature_mode' => $auth->certificate_mode->value,
                'valid_from' => $validation->validFrom,
                'valid_to' => $validation->validTo,
                'created_by_user_id' => $actorUserId,
                'segregation_class' => SerproDataSegregationClass::HistoricalUnverified->value,
                'metadata' => [
                    'schema_version' => TermoAutorizacaoGenerator::SCHEMA_VERSION,
                    'signature_checked' => $validation->signatureChecked,
                ],
            ]);

            $this->recordEvent($auth, $from, $auth->status, 'termo.upload', 'Termo assinado armazenado.', $actorUserId, [
                'termo_sha256' => $validation->sha256,
                'signature_checked' => $validation->signatureChecked,
                'version_number' => $versionNumber,
            ]);

            $this->audit->record('serpro.authorization.termo_upload', 'SUCCESS', $auth, [
                'termo_sha256' => $validation->sha256,
                'environment' => $environment->value,
                'version_number' => $versionNumber,
            ], $actorUserId, $office->id);

            return $auth->refresh();
        });
    }

    /**
     * A1 gerenciado opcional do Autor — consentimento versionado + purpose exclusivo.
     */
    public function storeManagedAuthorA1(
        Office $office,
        SerproEnvironment $environment,
        string $pfxBinary,
        string $password,
        bool $consent,
        ?int $actorUserId = null,
    ): OfficeSerproAuthorization {
        if (! $consent) {
            throw new RuntimeException('Consentimento explícito é obrigatório para A1 gerenciado.');
        }
        if ($actorUserId === null) {
            throw new RuntimeException('Actor (ADMIN) é obrigatório para custódia A1.');
        }
        $this->assertOfficeEligibleForEnvironment($office, $environment);

        $auth = $this->getOrCreate($office, $environment);
        $meta = $this->pfxReader->read($pfxBinary, $password);

        $holder = $this->normalizeIdentity($meta['cnpj']);
        if ($auth->author_identity_type === AuthorIdentityType::Cnpj) {
            if ($holder !== $auth->author_identity) {
                throw new RuntimeException('CNPJ do certificado A1 diverge do Autor configurado.');
            }
        }

        $aad = SecureObjectPurpose::SerproAuthorPfx->aadBase([
            'office_id' => $office->id,
            'environment' => $environment->value,
            'fingerprint' => $meta['fingerprint_sha256'],
            'author_identity' => $auth->author_identity,
        ]);

        $payload = json_encode([
            'pfx' => base64_encode($meta['pfx']),
            'password' => $meta['password'],
        ], JSON_THROW_ON_ERROR);

        $objectId = $this->store->put($payload, $aad);
        $previous = $auth->author_pfx_vault_object_id;
        $a1Changed = $previous !== null && $previous !== $objectId;

        $auth->certificate_mode = AuthorCertificateMode::ManagedA1;
        $auth->managed_a1_consent = true;
        $auth->managed_a1_consented_at = now();
        $auth->author_pfx_vault_object_id = $objectId;
        $auth->author_fingerprint_sha256 = $meta['fingerprint_sha256'];
        $auth->author_cert_valid_from = $meta['valid_from'];
        $auth->author_cert_valid_to = $meta['valid_to'];
        $auth->save();

        SerproAuthorizationConsent::query()->create([
            'office_id' => $office->id,
            'office_serpro_authorization_id' => $auth->id,
            'consent_type' => SerproAuthorizationConsent::TYPE_MANAGED_A1_CUSTODY,
            'version_code' => SerproAuthorizationConsent::VERSION_MANAGED_A1_V1,
            'actor_user_id' => $actorUserId,
            'consented_at' => now(),
            'payload_sha256' => hash('sha256', $meta['fingerprint_sha256'].'|'.SerproAuthorizationConsent::VERSION_MANAGED_A1_V1),
            'metadata' => ['fingerprint_sha256' => $meta['fingerprint_sha256']],
        ]);

        if ($previous !== null && $previous !== $objectId) {
            try {
                $this->store->delete($previous);
            } catch (Throwable) {
            }
        }

        if ($a1Changed) {
            $this->invalidateDerivedAuthorization(
                $auth,
                $office,
                $environment,
                reason: 'author_a1_changed',
                actorUserId: $actorUserId,
            );
        }

        unset($pfxBinary, $password, $meta, $payload);

        $this->audit->record('serpro.authorization.author_a1', 'SUCCESS', $auth, [
            'fingerprint_sha256' => $auth->author_fingerprint_sha256,
            'environment' => $environment->value,
        ], $actorUserId, $office->id);

        return $auth->refresh();
    }

    /**
     * Dispara assinatura A1 gerenciada (consentimento versionado + job dedicado).
     */
    public function dispatchManagedA1Sign(
        Office $office,
        SerproEnvironment $environment,
        bool $consent,
        ?int $actorUserId = null,
    ): OfficeSerproAuthorization {
        if (! $consent) {
            throw new RuntimeException('Consentimento versionado é obrigatório para assinar com A1 gerenciado.');
        }
        if ($actorUserId === null) {
            throw new RuntimeException('Actor (ADMIN+2FA) é obrigatório.');
        }

        $auth = $this->getOrCreate($office, $environment);
        if ($auth->certificate_mode !== AuthorCertificateMode::ManagedA1) {
            throw new RuntimeException('Configure A1 gerenciado antes de assinar.');
        }
        if (! $auth->managed_a1_consent || $auth->author_pfx_vault_object_id === null) {
            throw new RuntimeException('Custódia A1/consentimento ausente.');
        }

        $meta = is_array($auth->metadata) ? $auth->metadata : [];
        if (empty($meta['termo_draft_vault_object_id'])) {
            // Auto-gerar draft se ausente.
            $this->generateTermoDraft($office, $environment, null, $actorUserId);
            $auth = $auth->refresh();
        }

        SerproAuthorizationConsent::query()->create([
            'office_id' => $office->id,
            'office_serpro_authorization_id' => $auth->id,
            'consent_type' => SerproAuthorizationConsent::TYPE_MANAGED_A1,
            'version_code' => SerproAuthorizationConsent::VERSION_MANAGED_A1_V1,
            'actor_user_id' => $actorUserId,
            'consented_at' => now(),
            'payload_sha256' => hash(
                'sha256',
                ($auth->author_fingerprint_sha256 ?? '').'|sign|'.SerproAuthorizationConsent::VERSION_MANAGED_A1_V1,
            ),
            'metadata' => ['action' => 'sign_termo'],
        ]);

        SignTermoWithManagedA1Job::dispatch(
            $office->id,
            $environment->value,
            $auth->id,
            $actorUserId,
            $this->audit->correlationId(),
        );

        $this->audit->record('serpro.authorization.termo_managed_a1_dispatch', 'SUCCESS', $auth, [
            'environment' => $environment->value,
        ], $actorUserId, $office->id);

        return $auth->refresh();
    }

    public function markActionRequired(
        OfficeSerproAuthorization $auth,
        string $reason,
        ?int $actorUserId = null,
    ): OfficeSerproAuthorization {
        $from = $auth->status;
        $auth->status = SerproAuthorizationStatus::ActionRequired;
        $auth->action_required_reason = mb_substr($reason, 0, 500);
        $auth->save();

        $this->recordEvent($auth, $from, $auth->status, 'action_required', $reason, $actorUserId);

        return $auth;
    }

    /**
     * Renova token do procurador conforme estratégia de reapresentação do Termo.
     *
     * @param  bool  $force  Se true, renova mesmo com token ainda vigente (ação explícita do escritório).
     */
    public function refreshProcuradorToken(
        Office $office,
        SerproEnvironment $environment,
        ?int $actorUserId = null,
        bool $force = false,
    ): OfficeSerproAuthorization {
        // Dev: autenticar_procurador=fixture é Disabled — token local fixture.
        if (FiscalProfile::configured() === FiscalProfile::Dev) {
            return $this->activateDevFixtureAuthorization($office, $environment, $actorUserId);
        }

        $this->assertOfficeEligibleForEnvironment($office, $environment);
        $auth = $this->getOrCreate($office, $environment);

        if ($auth->termo_vault_object_id === null) {
            throw new RuntimeException('Termo assinado é obrigatório para obter token do procurador.');
        }

        if ($auth->certificate_mode === AuthorCertificateMode::InteractiveA3) {
            return $this->markActionRequired(
                $auth,
                'Certificado A3 exige assinatura interativa; não é possível renovar automaticamente.',
                $actorUserId,
            );
        }

        if (
            ! $force
            && $auth->procurador_token_vault_object_id !== null
            && $auth->procurador_token_expires_at !== null
            && $auth->procurador_token_expires_at->isFuture()
        ) {
            return $auth;
        }

        $strategy = $this->representationStrategy($environment);

        if (
            $auth->procurador_token_expires_at !== null
            && $auth->procurador_token_expires_at->isPast()
        ) {
            if ($strategy === TermRePresentationStrategy::RequireNewSignature) {
                return $this->markActionRequired(
                    $auth,
                    'Ambiente exige nova assinatura do Termo após expiração do token.',
                    $actorUserId,
                );
            }
            if ($strategy === TermRePresentationStrategy::PendingValidation) {
                return $this->markActionRequired(
                    $auth,
                    'Estratégia de reapresentação do Termo ainda PENDING_VALIDATION neste ambiente.',
                    $actorUserId,
                );
            }
        }

        $termoAad = SecureObjectPurpose::SerproTermoXml->aadBase([
            'office_id' => $office->id,
            'environment' => $environment->value,
            'kind' => 'signed',
            'sha256' => $auth->termo_sha256,
            'author_identity' => $auth->author_identity,
        ]);

        // Compat: termos gravados antes do kind=signed
        try {
            $termoXml = $this->store->get($auth->termo_vault_object_id, $termoAad);
        } catch (Throwable) {
            $legacyAad = SecureObjectPurpose::SerproTermoXml->aadBase([
                'office_id' => $office->id,
                'environment' => $environment->value,
                'sha256' => $auth->termo_sha256,
                'author_identity' => $auth->author_identity,
            ]);
            $termoXml = $this->store->get($auth->termo_vault_object_id, $legacyAad);
        }

        $contract = $this->contracts->activeFor($environment);
        if ($contract === null || ! $contract->isUsable()) {
            throw new RuntimeException('Contrato SERPRO indisponível para autenticar procurador.');
        }

        try {
            $token = $this->authenticator->authenticate($contract);
            $token->assertComplete();
            $bearer = $token->accessToken;
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Falha ao autenticar contrato SERPRO para token do procurador.',
                0,
                $e,
            );
        }

        $result = $this->procuradorClient()->authenticate(new ProcuradorAuthRequest(
            officeId: $office->id,
            environment: $environment->value,
            authorIdentity: $auth->author_identity,
            termoXml: $termoXml,
            contractorBearerToken: $bearer,
            correlationId: $this->audit->correlationId(),
        ));

        unset($termoXml, $bearer);

        if ($result->simulated) {
            throw new RuntimeException('Resposta simulada não é aceita para autenticar procurador.');
        }

        if ($result->requiresNewSignature || (! $result->success && $result->errorCode === 'SIGNATURE_REQUIRED')) {
            return $this->markActionRequired(
                $auth,
                $result->errorMessage ?? 'Nova assinatura do Termo exigida pelo ambiente.',
                $actorUserId,
            );
        }

        if (! $result->success || $result->token === null || $result->expiresAt === null) {
            $auth->termo_authorization_state = TermoAuthorizationState::Rejected;
            $auth->last_validation_result = $result->errorCode ?? 'SERPRO_REJECTED';
            $auth->last_validation_message = mb_substr(
                $result->errorMessage ?? 'Falha ao autenticar procurador.',
                0,
                500,
            );
            $auth->last_validated_at = now();
            $auth->save();

            throw new RuntimeException($result->errorMessage ?? 'Falha ao autenticar procurador.');
        }

        $tokenAad = SecureObjectPurpose::SerproProcuradorToken->aadBase([
            'office_id' => $office->id,
            'environment' => $environment->value,
            'author_identity' => $auth->author_identity,
        ]);

        $tokenPayload = json_encode([
            'token' => $result->token,
            'expires_at' => $result->expiresAt->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        $objectId = $this->store->put($tokenPayload, $tokenAad);
        $previous = $auth->procurador_token_vault_object_id;

        $from = $auth->status;
        $auth->procurador_token_vault_object_id = $objectId;
        $auth->procurador_token_expires_at = $result->expiresAt;
        // ETag sensível: não persistir em claro se parecer carregar token.
        $etag = $result->etag;
        if (is_string($etag) && (str_contains(strtolower($etag), 'token') || strlen($etag) > 64)) {
            $auth->procurador_etag = null;
            $meta = is_array($auth->metadata) ? $auth->metadata : [];
            $meta['has_procurador_etag'] = true;
            $auth->metadata = $meta;
        } else {
            $auth->procurador_etag = $etag;
        }

        if ($result->simulated) {
            throw new RuntimeException('Resposta sintética não pode criar token do procurador.');
        }

        $resolvedState = $result->authorizationState ?? TermoAuthorizationState::SerproAccepted->value;

        // Fail-closed: simulated nunca vira SERPRO_ACCEPTED.
        if (! $result->simulated && $resolvedState === TermoAuthorizationState::SerproAccepted->value) {
            // ok
        } elseif (! $result->simulated && $resolvedState !== TermoAuthorizationState::SerproAccepted->value) {
            // real path that didn't set state → accepted
            if ($result->success) {
                $resolvedState = TermoAuthorizationState::SerproAccepted->value;
            }
        }

        $auth->termo_authorization_state = TermoAuthorizationState::tryFrom($resolvedState)
            ?? TermoAuthorizationState::Rejected;
        $auth->last_token_refresh_at = now();
        $auth->status = SerproAuthorizationStatus::TokenActive;
        $auth->action_required_reason = null;
        $auth->save();

        if ($previous !== null && $previous !== $objectId) {
            try {
                $this->store->delete($previous);
            } catch (Throwable) {
            }
        }

        // Atualizar versão do Termo com token/aceite (referências opacas).
        $termVersion = SerproTermVersion::query()
            ->where('office_serpro_authorization_id', $auth->id)
            ->where('termo_sha256', $auth->termo_sha256)
            ->orderByDesc('version_number')
            ->first();
        if ($termVersion !== null) {
            $termVersion->token_vault_object_id = $objectId;
            $termVersion->token_expires_at = $result->expiresAt;
            if (! $result->simulated && $auth->termo_authorization_state === TermoAuthorizationState::SerproAccepted) {
                $termVersion->status = TermoAuthorizationState::SerproAccepted->value;
                $termVersion->serpro_accepted_at = now();
            }
            $termVersion->save();
        }

        $this->recordEvent($auth, $from, $auth->status, 'procurador.token_refresh', 'Token do procurador renovado.', $actorUserId, [
            'simulated' => $result->simulated,
            'expires_at' => $result->expiresAt->toIso8601String(),
            'authorization_state' => $auth->termo_authorization_state?->value,
        ]);

        try {
            $purged = app(SerproOperationAttemptStore::class)
                ->purgeNonStickyTokenFailures((int) $office->id, $environment->value);
            if ($purged > 0) {
                $this->audit->record('serpro.authorization.token_attempt_purge', 'SUCCESS', $auth, [
                    'purged' => $purged,
                    'environment' => $environment->value,
                ], $actorUserId, $office->id);
            }
        } catch (Throwable) {
            // Purge é best-effort: refresh do token já foi persistido.
        }

        $this->audit->record('serpro.authorization.token_refresh', 'SUCCESS', $auth, [
            'simulated' => $result->simulated,
            'expires_at' => $result->expiresAt->toIso8601String(),
            'authorization_state' => $auth->termo_authorization_state?->value,
        ], $actorUserId, $office->id);

        return $auth->refresh();
    }

    public function representationStrategy(SerproEnvironment $environment): TermRePresentationStrategy
    {
        $map = config('serpro.term_representation', []);
        $raw = is_array($map) ? ($map[$environment->value] ?? 'PENDING_VALIDATION') : 'PENDING_VALIDATION';

        return TermRePresentationStrategy::tryFrom((string) $raw)
            ?? TermRePresentationStrategy::PendingValidation;
    }

    /**
     * Ativa autorização local em FISCAL_PROFILE=dev (sem Apoiar real).
     * O driver fixture de autenticar_procurador é Disabled — sem isto a elegibilidade
     * fica eternamente em ACTION_REQUIRED / TOKEN_MISSING.
     */
    public function activateDevFixtureAuthorization(
        Office $office,
        SerproEnvironment $environment,
        ?int $actorUserId = null,
    ): OfficeSerproAuthorization {
        $auth = $this->getOrCreate($office, $environment);

        $tokenAad = SecureObjectPurpose::SerproProcuradorToken->aadBase([
            'office_id' => $office->id,
            'environment' => $environment->value,
            'author_identity' => $auth->author_identity,
        ]);

        $expiresAt = now()->addHours(12);
        $tokenPayload = json_encode([
            'token' => 'dev-fixture-procurador-token',
            'expires_at' => $expiresAt->toIso8601String(),
            'fixture' => true,
        ], JSON_THROW_ON_ERROR);

        $previous = $auth->procurador_token_vault_object_id;
        $objectId = $this->store->put($tokenPayload, $tokenAad);

        $from = $auth->status;
        $auth->procurador_token_vault_object_id = $objectId;
        $auth->procurador_token_expires_at = $expiresAt;
        $auth->last_token_refresh_at = now();
        $auth->status = SerproAuthorizationStatus::TokenActive;
        $auth->action_required_reason = null;
        if ($auth->termo_authorization_state === null
            || $auth->termo_authorization_state === TermoAuthorizationState::Draft
            || $auth->termo_authorization_state === TermoAuthorizationState::Rejected
        ) {
            $auth->termo_authorization_state = TermoAuthorizationState::LocalValidated;
        }
        $auth->save();

        if ($previous !== null && $previous !== $objectId) {
            try {
                $this->store->delete($previous);
            } catch (Throwable) {
            }
        }

        $this->recordEvent(
            $auth,
            $from,
            $auth->status,
            'token.dev_fixture',
            'Token fixture de desenvolvimento ativado.',
            $actorUserId,
        );

        $this->audit->record('serpro.authorization.token_dev_fixture', 'SUCCESS', $auth, [
            'environment' => $environment->value,
            'expires_at' => $expiresAt->toIso8601String(),
        ], $actorUserId, $office->id);

        try {
            app(SerproOperationAttemptStore::class)
                ->purgeNonStickyTokenFailures((int) $office->id, $environment->value);
        } catch (Throwable) {
        }

        return $auth->refresh();
    }

    /**
     * Invalida atomicamente token, ETag, cache e poderes derivados (retenção de Termo/auditoria).
     */
    public function invalidateDerivedAuthorization(
        OfficeSerproAuthorization $auth,
        Office $office,
        SerproEnvironment $environment,
        string $reason,
        ?int $actorUserId = null,
        bool $keepTermo = false,
    ): void {
        $termoHash = $auth->termo_sha256;
        $previousToken = $auth->procurador_token_vault_object_id;

        $auth->procurador_token_vault_object_id = null;
        $auth->procurador_token_expires_at = null;
        $auth->procurador_etag = null;
        $auth->last_token_refresh_at = null;

        if (! $keepTermo) {
            // Não apaga bytes do vault (retenção); só desassocia uso operacional se revogação total.
            if (in_array($reason, ['revoked', 'author_changed'], true)) {
                if ($auth->status === SerproAuthorizationStatus::TokenActive
                    || $auth->status === SerproAuthorizationStatus::TermValid) {
                    $auth->status = SerproAuthorizationStatus::PendingTerm;
                }
                if ($reason === 'author_changed') {
                    $auth->termo_vault_object_id = null;
                    $auth->termo_sha256 = null;
                    $auth->termo_signed_by = null;
                    $auth->termo_destination_cnpj = null;
                    $auth->termo_valid_from = null;
                    $auth->termo_valid_to = null;
                    $auth->termo_uploaded_at = null;
                    $auth->termo_authorization_state = TermoAuthorizationState::Draft;
                }
            }
        } else {
            // Termo novo: volta para estado local sem token.
            if ($auth->termo_authorization_state === TermoAuthorizationState::SerproAccepted) {
                $auth->termo_authorization_state = TermoAuthorizationState::LocalValidated;
            }
            if ($auth->status === SerproAuthorizationStatus::TokenActive) {
                $auth->status = SerproAuthorizationStatus::TermValid;
            }
        }

        $auth->save();

        // Cache meta do procurador (todas as chaves conhecidas para o autor/termo).
        if (is_string($termoHash) && $termoHash !== '') {
            $contract = $this->contracts->activeFor($environment);
            $contractKey = $contract !== null
                ? (string) ($contract->id ?? $contract->contractor_cnpj)
                : 'none';
            $cacheKey = sprintf(
                'serpro:procurador:meta:%d:%s:%s:%s:%s',
                $office->id,
                $environment->value,
                substr(hash('sha256', $contractKey), 0, 16),
                substr(hash('sha256', $auth->author_identity), 0, 16),
                substr($termoHash, 0, 16),
            );
            Cache::forget($cacheKey);
            // Legacy key sem contract (pré-4.9)
            Cache::forget(sprintf(
                'serpro:procurador:meta:%d:%s:%s:%s',
                $office->id,
                $environment->value,
                substr(hash('sha256', $auth->author_identity), 0, 16),
                substr($termoHash, 0, 16),
            ));
        }

        if (is_string($previousToken) && $previousToken !== '') {
            try {
                $this->store->delete($previousToken);
            } catch (Throwable) {
            }
        }

        // Poderes derivados: suspender uso (retenção de evidência).
        TaxProxyPower::query()
            ->where('office_id', $office->id)
            ->where('office_serpro_authorization_id', $auth->id)
            ->where('status', TaxProxyPowerStatus::Active->value)
            ->update([
                'status' => TaxProxyPowerStatus::Revoked->value,
                'last_check_result' => 'INVALIDATED:'.$reason,
                'updated_at' => now(),
            ]);

        // Snapshots não podem mascarar o ensure pré-consulta após troca/remoção de A1.
        ClientProcuracaoSnapshot::query()
            ->where('office_id', $office->id)
            ->where('environment', $environment->value)
            ->update([
                'status' => ClientProcuracaoSyncStatus::Unverified->value,
                'last_check_result' => 'INVALIDATED:'.$reason,
                'updated_at' => now(),
            ]);

        $this->recordEvent(
            $auth,
            $auth->status,
            $auth->status,
            'authorization.invalidate_derived',
            'Token/cache/poderes invalidados: '.$reason,
            $actorUserId,
            ['reason' => $reason, 'keep_termo' => $keepTermo],
        );

        $this->audit->record('serpro.authorization.invalidate_derived', 'SUCCESS', $auth, [
            'reason' => $reason,
            'environment' => $environment->value,
        ], $actorUserId, $office->id);
    }

    /**
     * @return array{0: string, 1: string} [cnpj, nome]
     */
    private function resolveDestination(SerproEnvironment $environment): array
    {
        $destination = (string) config('serpro.termo_destination_cnpj', '');
        $name = (string) config('serpro.termo_destination_name', 'CONTRATANTE');
        $contract = $this->contracts->activeFor($environment);
        if ($destination === '' && $contract !== null) {
            $destination = (string) $contract->contractor_cnpj;
        }
        if ($destination === '') {
            throw new RuntimeException('CNPJ destinatário do Termo (contratante) não configurado.');
        }
        if ($contract !== null && property_exists($contract, 'contractor_name') && is_string($contract->contractor_name) && $contract->contractor_name !== '') {
            $name = $contract->contractor_name;
        }

        return [$this->normalizeIdentity($destination), $name];
    }

    private function recordEvent(
        OfficeSerproAuthorization $auth,
        SerproAuthorizationStatus|string|null $from,
        SerproAuthorizationStatus|string $to,
        string $event,
        ?string $message,
        ?int $actorUserId,
        array $context = [],
    ): void {
        OfficeSerproAuthorizationEvent::query()->create([
            'office_id' => $auth->office_id,
            'office_serpro_authorization_id' => $auth->id,
            'from_status' => $from instanceof SerproAuthorizationStatus ? $from->value : $from,
            'to_status' => $to instanceof SerproAuthorizationStatus ? $to->value : $to,
            'event' => $event,
            'message' => $message !== null ? mb_substr($message, 0, 500) : null,
            'actor_user_id' => $actorUserId,
            'context' => $context,
            'created_at' => now(),
        ]);
    }

    private function normalizeIdentity(string $raw): string
    {
        return BrazilianTaxId::normalize($raw);
    }

    private function assertIdentity(AuthorIdentityType $type, string $identity): void
    {
        if ($type === AuthorIdentityType::Cpf && strlen($identity) !== 11) {
            throw new RuntimeException('CPF do Autor deve ter 11 dígitos.');
        }
        if ($type === AuthorIdentityType::Cnpj && strlen($identity) !== 14) {
            throw new RuntimeException('CNPJ do Autor deve ter 14 caracteres.');
        }
    }

    /**
     * Bloqueia Office demo no ambiente produtivo.
     */
    public function assertOfficeEligibleForEnvironment(Office $office, SerproEnvironment $environment): void
    {
        app(SerproProductionOnboardingGuard::class)
            ->assertMayUseRealEndpoint($office, $environment);
    }
}

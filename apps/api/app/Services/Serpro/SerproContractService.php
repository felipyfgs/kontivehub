<?php

namespace App\Services\Serpro;

use App\Contracts\PfxReaderInterface;
use App\Contracts\SecureObjectStore;
use App\Domain\Cnpj;
use App\Enums\SecureObjectPurpose;
use App\Enums\SerproContractStatus;
use App\Enums\SerproEnvironment;
use App\Models\SerproContract;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Ciclo de vida do contrato SERPRO global (plano de controle).
 * Sem rota de recuperação de segredo; APIs/CLI só metadados sanitizados.
 */
final class SerproContractService
{
    public function __construct(
        private readonly SecureObjectStore $store,
        private readonly PfxReaderInterface $pfxReader,
        private readonly AuditLogger $audit,
        private readonly SerproKillSwitchService $killSwitch,
    ) {}

    public function activeFor(SerproEnvironment $environment): ?SerproContract
    {
        return SerproContract::query()
            ->where('environment', $environment->value)
            ->where('status', SerproContractStatus::Active->value)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Cadastra contrato PENDING com PFX + OAuth no cofre.
     */
    public function register(
        SerproEnvironment $environment,
        string $pfxBinary,
        string $password,
        string $consumerKey,
        string $consumerSecret,
        ?string $contractorName = null,
        ?string $notes = null,
        ?int $actorUserId = null,
    ): SerproContract {
        $meta = $this->validateAndReadPfx($pfxBinary, $password);
        $holder = Cnpj::parse($meta['cnpj']);

        $consumerKey = trim($consumerKey);
        $consumerSecret = trim($consumerSecret);
        if ($consumerKey === '' || $consumerSecret === '') {
            throw new RuntimeException('Consumer Key e Consumer Secret são obrigatórios.');
        }

        $pfxAad = SecureObjectPurpose::SerproContractorPfx->aadBase([
            'environment' => $environment->value,
            'fingerprint' => $meta['fingerprint_sha256'],
            'contractor_cnpj' => $holder->value(),
        ]);

        $pfxPayload = json_encode([
            'pfx' => base64_encode($meta['pfx']),
            'password' => $meta['password'],
        ], JSON_THROW_ON_ERROR);

        $oauthAad = SecureObjectPurpose::SerproOauthSecrets->aadBase([
            'environment' => $environment->value,
            'contractor_cnpj' => $holder->value(),
        ]);

        $oauthPayload = json_encode([
            'consumer_key' => $consumerKey,
            'consumer_secret' => $consumerSecret,
        ], JSON_THROW_ON_ERROR);

        $pfxObjectId = $this->store->put($pfxPayload, $pfxAad);
        $oauthObjectId = null;

        try {
            $oauthObjectId = $this->store->put($oauthPayload, $oauthAad);

            $contract = SerproContract::query()->create([
                'environment' => $environment,
                'status' => SerproContractStatus::Pending,
                'contractor_cnpj' => $holder->value(),
                'contractor_name' => $contractorName,
                'subject_name' => $meta['subject_name'],
                'fingerprint_sha256' => $meta['fingerprint_sha256'],
                'cert_valid_from' => $meta['valid_from'],
                'cert_valid_to' => $meta['valid_to'],
                'pfx_vault_object_id' => $pfxObjectId,
                'oauth_vault_object_id' => $oauthObjectId,
                'consumer_key_hint' => $this->hint($consumerKey),
                'health_status' => 'PENDING',
                'health_message' => 'Contrato cadastrado; aguardando ativação.',
                'last_verified_at' => now(),
                'notes' => $notes,
            ]);
        } catch (Throwable $e) {
            $this->safeDelete($pfxObjectId);
            if ($oauthObjectId !== null) {
                $this->safeDelete($oauthObjectId);
            }
            throw $e;
        } finally {
            // Descartar buffers sensíveis do escopo local (melhor esforço).
            unset($pfxBinary, $password, $meta, $pfxPayload, $oauthPayload, $consumerSecret);
        }

        $this->audit->record('serpro.contract.register', 'SUCCESS', $contract, [
            'environment' => $environment->value,
            'fingerprint_sha256' => $contract->fingerprint_sha256,
            'contractor_cnpj_masked' => substr($holder->value(), 0, 4).'****',
        ], $actorUserId, null);

        return $contract;
    }

    /**
     * Ativa o contrato; se já houver ACTIVE no ambiente, exige replace=true (substituição).
     * Produção exige confirmação OWNER vinculada (CONTRACT_ACTIVATE).
     */
    public function activate(
        SerproContract $contract,
        bool $replace = false,
        ?int $actorUserId = null,
        ?int $approvalId = null,
    ): SerproContract {
        if ($contract->status === SerproContractStatus::Active) {
            return $contract;
        }

        if (! in_array($contract->status, [SerproContractStatus::Pending, SerproContractStatus::Inactive], true)) {
            throw new RuntimeException('Somente contratos PENDING ou INACTIVE podem ser ativados.');
        }

        if ($contract->pfx_vault_object_id === null || $contract->oauth_vault_object_id === null) {
            throw new RuntimeException('Contrato sem material de credencial no cofre.');
        }

        return DB::transaction(function () use ($contract, $replace, $actorUserId, $approvalId): SerproContract {
            $envEnum = $contract->environment instanceof SerproEnvironment
                ? $contract->environment
                : SerproEnvironment::from((string) $contract->environment);
            $env = $envEnum->value;

            $ownerApproval = null;
            $requiresOwner = $envEnum === SerproEnvironment::Production
                || (bool) config('serpro.owner_confirmation.require_for_all_environments', false);

            if ($requiresOwner) {
                if ($actorUserId === null || $actorUserId <= 0) {
                    throw new RuntimeException(
                        'Ativação de contrato exige ator com confirmação OWNER vinculada (CONTRACT_ACTIVATE).'
                    );
                }
                $ownerApproval = app(SerproRolloutApprovalService::class)->claimOwnerApproval(
                    action: SerproRolloutApprovalService::ACTION_CONTRACT_ACTIVATE,
                    subjectType: 'CONTRACT',
                    subjectId: (int) $contract->id,
                    environment: $envEnum,
                    actorUserId: $actorUserId,
                    approvalId: $approvalId,
                );
            }

            $existing = SerproContract::query()
                ->where('environment', $env)
                ->where('status', SerproContractStatus::Active->value)
                ->where('id', '!=', $contract->id)
                ->lockForUpdate()
                ->get();

            if ($existing->isNotEmpty() && ! $replace) {
                throw new RuntimeException(
                    'Já existe contrato ACTIVE neste ambiente. Use substituição transacional (replace).',
                );
            }

            foreach ($existing as $old) {
                $old->status = SerproContractStatus::Superseded;
                $old->superseded_at = now();
                $old->health_status = 'SUPERSEDED';
                $old->save();

                $this->audit->record('serpro.contract.supersede', 'SUCCESS', $old, [
                    'environment' => $env,
                    'replaced_by' => $contract->id,
                ], $actorUserId, null);
            }

            $contract->status = SerproContractStatus::Active;
            $contract->activated_at = now();
            $contract->health_status = 'OK';
            $contract->health_message = 'Contrato ativo.';
            $contract->save();

            if ($ownerApproval !== null) {
                app(SerproRolloutApprovalService::class)->markOwnerApprovalExecuted(
                    $ownerApproval,
                    (int) $actorUserId,
                );
            }

            $this->audit->record('serpro.contract.activate', 'SUCCESS', $contract, [
                'environment' => $env,
                'replaced' => $existing->isNotEmpty(),
                'approval_id' => $ownerApproval?->id,
                'approval_policy' => $ownerApproval !== null ? 'OWNER_CONFIRMATION' : null,
            ], $actorUserId, null);

            return $contract->refresh();
        });
    }

    /**
     * Substitui o ACTIVE atual por um novo cadastro (register + activate replace).
     */
    public function replaceActive(
        SerproEnvironment $environment,
        string $pfxBinary,
        string $password,
        string $consumerKey,
        string $consumerSecret,
        ?string $contractorName = null,
        ?string $notes = null,
        ?int $actorUserId = null,
    ): SerproContract {
        $pending = $this->register(
            $environment,
            $pfxBinary,
            $password,
            $consumerKey,
            $consumerSecret,
            $contractorName,
            $notes,
            $actorUserId,
        );

        return $this->activate($pending, replace: true, actorUserId: $actorUserId);
    }

    public function deactivate(SerproContract $contract, ?string $reason = null, ?int $actorUserId = null): SerproContract
    {
        $contract->status = SerproContractStatus::Inactive;
        $contract->health_status = 'INACTIVE';
        $contract->health_message = $reason ?? 'Contrato desativado.';
        $contract->save();

        $this->audit->record('serpro.contract.deactivate', 'SUCCESS', $contract, [
            'environment' => $contract->environment->value,
            'reason' => $reason !== null ? mb_substr($reason, 0, 200) : null,
        ], $actorUserId, null);

        return $contract;
    }

    public function block(SerproContract $contract, string $reason, ?int $actorUserId = null): SerproContract
    {
        $contract->status = SerproContractStatus::Blocked;
        $contract->blocked_at = now();
        $contract->health_status = 'BLOCKED';
        $contract->health_message = mb_substr($reason, 0, 500);
        $contract->save();

        $this->killSwitch->activateGlobal('contract_blocked: '.mb_substr($reason, 0, 100), $actorUserId);

        $this->audit->record('serpro.contract.block', 'SUCCESS', $contract, [
            'environment' => $contract->environment->value,
            'reason' => mb_substr($reason, 0, 200),
        ], $actorUserId, null);

        return $contract;
    }

    /**
     * Material PFX em memória para mTLS — nunca expor via API.
     *
     * @return array{pfx: string, password: string}
     */
    public function loadPfxMaterial(SerproContract $contract): array
    {
        if ($contract->pfx_vault_object_id === null) {
            throw new RuntimeException('Contrato sem PFX no cofre.');
        }

        $aad = SecureObjectPurpose::SerproContractorPfx->aadBase([
            'environment' => $contract->environment->value,
            'fingerprint' => $contract->fingerprint_sha256,
            'contractor_cnpj' => $contract->contractor_cnpj,
        ]);

        $json = $this->store->get($contract->pfx_vault_object_id, $aad);
        /** @var array{pfx: string, password: string} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $pfx = base64_decode((string) ($data['pfx'] ?? ''), true);
        if ($pfx === false || $pfx === '') {
            throw new RuntimeException('Material PFX do contrato corrompido no cofre.');
        }

        return [
            'pfx' => $pfx,
            'password' => (string) ($data['password'] ?? ''),
        ];
    }

    /**
     * @return array{consumer_key: string, consumer_secret: string}
     */
    public function loadOauthSecrets(SerproContract $contract): array
    {
        if ($contract->oauth_vault_object_id === null) {
            throw new RuntimeException('Contrato sem OAuth no cofre.');
        }

        $aad = SecureObjectPurpose::SerproOauthSecrets->aadBase([
            'environment' => $contract->environment->value,
            'contractor_cnpj' => $contract->contractor_cnpj,
        ]);

        $json = $this->store->get($contract->oauth_vault_object_id, $aad);
        /** @var array{consumer_key: string, consumer_secret: string} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return [
            'consumer_key' => (string) ($data['consumer_key'] ?? ''),
            'consumer_secret' => (string) ($data['consumer_secret'] ?? ''),
        ];
    }

    /**
     * Armazena o bearer específico do gateway oficial Trial no cofre.
     * O banco mantém apenas a referência opaca; o valor nunca é reexibido.
     */
    public function storeTrialGatewayBearer(
        SerproContract $contract,
        string $bearerToken,
        ?int $actorUserId = null,
    ): SerproContract {
        if ($contract->environment !== SerproEnvironment::Trial) {
            throw new RuntimeException('Bearer do gateway Trial só pode ser associado a contrato TRIAL.');
        }

        $bearerToken = trim($bearerToken);
        if ($bearerToken === '' || strlen($bearerToken) > 4096) {
            throw new RuntimeException('Bearer do gateway Trial ausente ou inválido.');
        }

        $aad = SecureObjectPurpose::SerproTrialGatewayBearer->aadBase([
            'environment' => SerproEnvironment::Trial->value,
            'contract_id' => (int) $contract->id,
            'contractor_cnpj' => $contract->contractor_cnpj,
        ]);
        $payload = json_encode(['bearer_token' => $bearerToken], JSON_THROW_ON_ERROR);
        $objectId = $this->store->put($payload, $aad);
        $previous = $contract->trial_bearer_vault_object_id;

        try {
            $contract->trial_bearer_vault_object_id = $objectId;
            $contract->save();
        } catch (Throwable $e) {
            $this->safeDelete($objectId);
            throw $e;
        } finally {
            unset($bearerToken, $payload);
        }

        if (is_string($previous) && $previous !== '' && $previous !== $objectId) {
            $this->safeDelete($previous);
        }

        $this->audit->record('serpro.contract.trial_bearer.store', 'SUCCESS', $contract, [
            'environment' => SerproEnvironment::Trial->value,
            'has_trial_bearer' => true,
        ], $actorUserId, null);

        return $contract->refresh();
    }

    public function loadTrialGatewayBearer(SerproContract $contract): string
    {
        if ($contract->environment !== SerproEnvironment::Trial
            || $contract->trial_bearer_vault_object_id === null
        ) {
            throw new RuntimeException('Bearer do gateway Trial não cadastrado no banco/cofre.');
        }

        $aad = SecureObjectPurpose::SerproTrialGatewayBearer->aadBase([
            'environment' => SerproEnvironment::Trial->value,
            'contract_id' => (int) $contract->id,
            'contractor_cnpj' => $contract->contractor_cnpj,
        ]);
        $json = $this->store->get($contract->trial_bearer_vault_object_id, $aad);
        /** @var array{bearer_token?: string} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $token = trim((string) ($data['bearer_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('Bearer do gateway Trial corrompido no cofre.');
        }

        return $token;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSanitized(?SerproEnvironment $environment = null): array
    {
        $q = SerproContract::query()->orderByDesc('id');
        if ($environment !== null) {
            $q->where('environment', $environment->value);
        }

        return $q->get()->map(fn (SerproContract $c) => $c->toSanitizedArray())->all();
    }

    /**
     * @return array{pfx: string, password: string, subject_name: string, cnpj: string, fingerprint_sha256: string, valid_from: CarbonImmutable, valid_to: CarbonImmutable}
     */
    private function validateAndReadPfx(string $pfxBinary, string $password): array
    {
        if ($pfxBinary === '') {
            throw new RuntimeException('PFX vazio.');
        }

        $meta = $this->pfxReader->read($pfxBinary, $password);

        if ($meta['valid_to']->isPast()) {
            throw new RuntimeException('Certificado contratante expirado.');
        }

        if ($meta['fingerprint_sha256'] === '' || strlen($meta['fingerprint_sha256']) < 32) {
            throw new RuntimeException('Fingerprint do certificado inválido.');
        }

        Cnpj::parse($meta['cnpj']);

        return $meta;
    }

    private function hint(string $consumerKey): string
    {
        $len = strlen($consumerKey);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($consumerKey, 0, 2).str_repeat('*', min(8, $len - 4)).substr($consumerKey, -2);
    }

    private function safeDelete(string $objectId): void
    {
        try {
            $this->store->delete($objectId);
        } catch (Throwable $e) {
            report(new RuntimeException('Falha ao compensar objeto SERPRO no cofre.', 0, $e));
        }
    }
}

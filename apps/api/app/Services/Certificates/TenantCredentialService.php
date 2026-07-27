<?php

namespace App\Services\Certificates;

use App\Contracts\PfxReaderInterface;
use App\Contracts\SecureObjectStore;
use App\Domain\Cnpj;
use App\Enums\CredentialStatus;
use App\Enums\FiscalProfile;
use App\Enums\SyncCursorStatus;
use App\Enums\TenantCredentialPurpose;
use App\Models\Tenant;
use App\Models\TenantCredential;
use App\Models\TenantCredentialPurposeLink;
use App\Models\TenantDistributionCursor;
use App\Models\TenantInstitutionalProfile;
use App\Services\Integra\TenantSerproOnboardingService;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Ciclo de vida do certificado do escritório.
 *
 * Um certificado físico por tenant, com vínculos de finalidade
 * (SERPRO_TERM_SIGNING, NFE_AUTXML_DISTDFE) sem duplicar o segredo.
 * Nunca materializa PEM em disco; PFX só em memória via vault.
 */
final class TenantCredentialService
{
    /** Finalidades vinculadas automaticamente ao certificado. */
    public const DEFAULT_PURPOSE_LINKS = [
        TenantCredentialPurpose::SerproTermSigning,
        TenantCredentialPurpose::NfeAutXmlDistDfe,
    ];

    public function __construct(
        private readonly SecureObjectStore $store,
        private readonly PfxReaderInterface $pfxReader,
        private readonly CurrentTenant $currentTenant,
        private readonly TenantSerproOnboardingService $onboarding,
    ) {}

    /**
     * Ativa/substitui o certificado do CurrentTenant.
     * Valida antes de ativar: falha de validação não altera a anterior.
     *
     * @param  list<TenantCredentialPurpose>|null  $linkPurposes
     */
    public function activate(
        string $pfxBinary,
        string $password,
        ?int $actorUserId = null,
        ?array $linkPurposes = null,
    ): TenantCredential {
        $tenant = $this->currentTenant->tenant();
        $profile = TenantInstitutionalProfile::query()
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($profile === null || $profile->cnpj === null || $profile->cnpj === '') {
            throw new RuntimeException(
                'Cadastre o CNPJ do perfil institucional antes do certificado.'
            );
        }

        // Validação completa antes de qualquer mutação.
        $meta = $this->validatePfx($pfxBinary, $password, $profile->cnpj);

        return $this->persistActivation($tenant, $meta, $actorUserId, $linkPurposes);
    }

    /**
     * Substitui o certificado (mesmo fluxo de activate; nome semântico para API).
     *
     * @param  list<TenantCredentialPurpose>|null  $linkPurposes
     */
    public function replace(
        string $pfxBinary,
        string $password,
        ?int $actorUserId = null,
        ?array $linkPurposes = null,
    ): TenantCredential {
        return $this->activate($pfxBinary, $password, $actorUserId, $linkPurposes);
    }

    /**
     * Remoção confirmada do certificado: revoga vínculos, bloqueia finalidades e dispara reonboarding.
     */
    public function remove(
        bool $confirmed,
        ?int $actorUserId = null,
        string $reason = 'Removida pelo administrador.',
    ): ?TenantCredential {
        if (! $confirmed) {
            throw new RuntimeException(
                'A remoção do certificado exige confirmação (confirm=true).'
            );
        }

        $credential = $this->activeForCurrentTenant();
        if ($credential === null) {
            return null;
        }

        $this->revokeCredential($credential, $reason, removeVault: true, triggerReonboarding: true, actorUserId: $actorUserId);

        return $credential->fresh();
    }

    public function activeForCurrentTenant(): ?TenantCredential
    {
        return $this->active($this->currentTenant->tenant()->id);
    }

    public function active(int $tenantId): ?TenantCredential
    {
        return TenantCredential::query()
            ->where('tenant_id', $tenantId)
            ->where('status', CredentialStatus::Active)
            ->first();
    }

    /**
     * Resolve a certificado ativa por finalidade (vínculo).
     */
    public function activeForPurpose(int $tenantId, TenantCredentialPurpose $purpose): ?TenantCredential
    {
        $link = TenantCredentialPurposeLink::query()
            ->where('tenant_id', $tenantId)
            ->where('purpose', $purpose->value)
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

    public function revoke(TenantCredential $credential, string $reason = 'Revogada pelo administrador.'): void
    {
        $tenantId = $this->currentTenant->tenant()->id;
        if ($credential->tenant_id !== $tenantId) {
            abort(404);
        }

        $this->revokeCredential($credential, $reason, removeVault: true, triggerReonboarding: true);
    }

    private function revokeCredential(
        TenantCredential $credential,
        string $reason = 'Revogada pelo administrador.',
        bool $removeVault = true,
        bool $triggerReonboarding = true,
        ?int $actorUserId = null,
    ): void {
        $tenantId = (int) $credential->tenant_id;

        DB::transaction(function () use ($credential, $tenantId): void {
            $locked = TenantCredential::query()->whereKey($credential->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CredentialStatus::Revoked) {
                $locked->status = CredentialStatus::Revoked;
                $locked->superseded_at = now();
                $locked->save();
            }

            TenantCredentialPurposeLink::query()
                ->where('tenant_id', $tenantId)
                ->where('tenant_credential_id', $locked->id)
                ->where('status', CredentialStatus::Active)
                ->lockForUpdate()
                ->get()
                ->each(function (TenantCredentialPurposeLink $link): void {
                    $link->status = CredentialStatus::Revoked;
                    $link->revoked_at = now();
                    $link->save();
                });
        });

        // Bloqueia cursores autXML do tenant (qualquer identidade).
        $this->blockAutXmlCursorsForTenant($tenantId, $reason);

        if ($removeVault && $credential->vault_object_id) {
            $this->invalidateSupersededObject((int) $credential->id, (string) $credential->vault_object_id);
        }

        if ($triggerReonboarding) {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant !== null) {
                foreach ([FiscalProfile::configured()->serproEnvironment()] as $env) {
                    $this->onboarding->reactToProfileOrCredentialChange(
                        $tenant,
                        $env,
                        'certificate_removed',
                        $actorUserId,
                    );
                }
            }
        }
    }

    /**
     * Material sensível apenas em memória — nunca expor via API.
     *
     * @return array{pfx: string, password: string}|null
     */
    public function loadPfxMaterial(TenantCredential $credential): ?array
    {
        if (! $credential->status->isUsable()) {
            return null;
        }

        if ($credential->valid_to->isPast()) {
            $credential->status = CredentialStatus::Expired;
            $credential->save();
            $this->blockAutXmlCursorsForTenant(
                (int) $credential->tenant_id,
                'certificado do escritório expirada.',
            );

            return null;
        }

        $aad = $this->vaultAad($credential);

        $json = $this->store->get($credential->vault_object_id, $aad);
        /** @var array{pfx: string, password: string} $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        $pfx = base64_decode((string) ($data['pfx'] ?? ''), true);
        if ($pfx === false || $pfx === '') {
            throw new RuntimeException('Material PFX do escritório corrompido no cofre.');
        }

        $credential->last_used_at = now();
        $credential->save();

        return [
            'pfx' => $pfx,
            'password' => (string) ($data['password'] ?? ''),
        ];
    }

    /**
     * Gera/deduplica alertas de painel nas janelas 30/7/1 dias (sem e-mail/WhatsApp/SMS).
     *
     * @return array{credentials: int, cursors_blocked: int}
     */
    public function refreshExpiryAlerts(): array
    {
        $credentialsUpdated = 0;
        $cursorsBlocked = 0;
        $now = now();

        TenantCredential::query()
            ->where('status', CredentialStatus::Active)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($now, &$credentialsUpdated, &$cursorsBlocked): void {
                foreach ($rows as $credential) {
                    /** @var TenantCredential $credential */
                    if ($credential->valid_to->isPast()) {
                        $credential->status = CredentialStatus::Expired;
                        $credential->save();
                        $credentialsUpdated++;
                        $cursorsBlocked += $this->blockAutXmlCursorsForTenant(
                            (int) $credential->tenant_id,
                            'certificado do escritório expirada.',
                        );

                        continue;
                    }

                    $days = (int) floor($now->floatDiffInRealDays($credential->valid_to, false));
                    $changed = false;
                    if ($days <= 30 && ! $credential->expires_alert_30) {
                        $credential->expires_alert_30 = true;
                        $changed = true;
                    }
                    if ($days <= 7 && ! $credential->expires_alert_7) {
                        $credential->expires_alert_7 = true;
                        $changed = true;
                    }
                    if ($days <= 1 && ! $credential->expires_alert_1) {
                        $credential->expires_alert_1 = true;
                        $changed = true;
                    }
                    if ($changed) {
                        $credential->save();
                        $credentialsUpdated++;
                    }
                }
            });

        return ['credentials' => $credentialsUpdated, 'cursors_blocked' => $cursorsBlocked];
    }

    /**
     * Alertas de painel deduplicados para a certificado ativa.
     *
     * @return list<array{window_days: int, code: string, message: string}>
     */
    public function panelExpiryAlerts(?TenantCredential $credential = null): array
    {
        $credential ??= $this->activeForCurrentTenant();
        if ($credential === null || ! $credential->status->isUsable()) {
            return [];
        }

        $alerts = [];
        if ($credential->expires_alert_1) {
            $alerts[] = [
                'window_days' => 1,
                'code' => 'CERTIFICATE_EXPIRES_1D',
                'message' => 'O certificado do escritório vence em até 1 dia.',
            ];
        } elseif ($credential->expires_alert_7) {
            $alerts[] = [
                'window_days' => 7,
                'code' => 'CERTIFICATE_EXPIRES_7D',
                'message' => 'O certificado do escritório vence em até 7 dias.',
            ];
        } elseif ($credential->expires_alert_30) {
            $alerts[] = [
                'window_days' => 30,
                'code' => 'CERTIFICATE_EXPIRES_30D',
                'message' => 'O certificado do escritório vence em até 30 dias.',
            ];
        }

        return $alerts;
    }

    /**
     * @return array{
     *   pfx: string,
     *   password: string,
     *   subject_name: string,
     *   cnpj: string,
     *   fingerprint_sha256: string,
     *   valid_from: CarbonImmutable,
     *   valid_to: CarbonImmutable
     * }
     */
    private function validatePfx(string $pfxBinary, string $password, string $expectedCnpj): array
    {
        if ($pfxBinary === '') {
            throw new RuntimeException('Arquivo PFX vazio.');
        }

        try {
            $meta = $this->pfxReader->read($pfxBinary, $password);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('Não foi possível validar o certificado.', 0, $e);
        }

        $holder = Cnpj::parse($meta['cnpj']);
        $expected = Cnpj::parse($expectedCnpj);

        // Titularidade exata (14 caracteres), não apenas raiz.
        if (! $holder->equals($expected)) {
            throw new RuntimeException(
                'O CNPJ titular do certificado deve ser exatamente igual ao CNPJ do perfil institucional.'
            );
        }

        if ($meta['valid_to']->isPast()) {
            throw new RuntimeException('Certificado expirado.');
        }

        return $meta;
    }

    /**
     * @param  array{
     *   pfx: string,
     *   password: string,
     *   subject_name: string,
     *   cnpj: string,
     *   fingerprint_sha256: string,
     *   valid_from: CarbonImmutable,
     *   valid_to: CarbonImmutable
     * }  $meta
     * @param  list<TenantCredentialPurpose>|null  $linkPurposes
     */
    private function persistActivation(
        Tenant $tenant,
        array $meta,
        ?int $actorUserId,
        ?array $linkPurposes,
    ): TenantCredential {
        $tenantId = $tenant->id;
        $holder = Cnpj::parse($meta['cnpj']);
        $linkPurposes ??= self::DEFAULT_PURPOSE_LINKS;

        $payload = json_encode([
            'pfx' => base64_encode($meta['pfx']),
            'password' => $meta['password'],
        ], JSON_THROW_ON_ERROR);

        $aad = [
            'tenant_id' => $tenantId,
            'credential_type' => 'CERTIFICATE',
            'fingerprint' => $meta['fingerprint_sha256'],
        ];

        $objectId = $this->store->put($payload, $aad);
        $superseded = [];

        try {
            $credential = DB::transaction(function () use (
                $meta,
                $objectId,
                $tenantId,
                $holder,
                $linkPurposes,
                $actorUserId,
                &$superseded,
            ): TenantCredential {
                // Serializa ativações concorrentes por tenant.
                Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

                $previous = TenantCredential::query()
                    ->where('tenant_id', $tenantId)
                    ->where('status', CredentialStatus::Active)
                    ->lockForUpdate()
                    ->get();

                foreach ($previous as $old) {
                    $superseded[] = [
                        'id' => $old->id,
                        'object_id' => $old->vault_object_id,
                    ];
                    $old->status = CredentialStatus::Superseded;
                    $old->superseded_at = now();
                    $old->save();

                    TenantCredentialPurposeLink::query()
                        ->where('tenant_credential_id', $old->id)
                        ->where('status', CredentialStatus::Active)
                        ->lockForUpdate()
                        ->get()
                        ->each(function (TenantCredentialPurposeLink $link): void {
                            $link->status = CredentialStatus::Revoked;
                            $link->revoked_at = now();
                            $link->save();
                        });
                }

                $created = TenantCredential::query()->create([
                    'tenant_id' => $tenantId,
                    'status' => CredentialStatus::Active,
                    'subject_name' => $meta['subject_name'],
                    'holder_cnpj' => $holder->value(),
                    'fingerprint_sha256' => $meta['fingerprint_sha256'],
                    'valid_from' => $meta['valid_from'],
                    'valid_to' => $meta['valid_to'],
                    'vault_object_id' => $objectId,
                    'activated_at' => now(),
                    'expires_alert_30' => false,
                    'expires_alert_7' => false,
                    'expires_alert_1' => false,
                ]);

                foreach ($linkPurposes as $linkPurpose) {
                    if (! $linkPurpose instanceof TenantCredentialPurpose) {
                        continue;
                    }

                    // Revoga vínculo ativo anterior da mesma finalidade (outro credential).
                    TenantCredentialPurposeLink::query()
                        ->where('tenant_id', $tenantId)
                        ->where('purpose', $linkPurpose->value)
                        ->where('status', CredentialStatus::Active)
                        ->lockForUpdate()
                        ->get()
                        ->each(function (TenantCredentialPurposeLink $link): void {
                            $link->status = CredentialStatus::Revoked;
                            $link->revoked_at = now();
                            $link->save();
                        });

                    TenantCredentialPurposeLink::query()->create([
                        'tenant_id' => $tenantId,
                        'tenant_credential_id' => $created->id,
                        'purpose' => $linkPurpose,
                        'status' => CredentialStatus::Active,
                        'linked_at' => now(),
                        'revoked_at' => null,
                        'linked_by_user_id' => $actorUserId,
                        'metadata' => null,
                    ]);
                }

                return $created;
            });
        } catch (Throwable $e) {
            try {
                $this->store->delete($objectId);
            } catch (Throwable $cleanupError) {
                report(new RuntimeException('Falha ao compensar objeto de certificado.', 0, $cleanupError));
            }

            throw $e;
        }

        foreach ($superseded as $old) {
            $this->invalidateSupersededObject($old['id'], $old['object_id']);
        }

        // Reonboarding das finalidades derivadas (Termo/token).
        foreach ([FiscalProfile::configured()->serproEnvironment()] as $env) {
            $this->onboarding->reactToProfileOrCredentialChange(
                $tenant,
                $env,
                'certificate_replaced',
                $actorUserId,
            );
        }

        return $credential;
    }

    /**
     * @return array<string, mixed>
     */
    private function vaultAad(TenantCredential $credential): array
    {
        return [
            'tenant_id' => $credential->tenant_id,
            'credential_type' => 'CERTIFICATE',
            'fingerprint' => $credential->fingerprint_sha256,
        ];
    }

    private function blockAutXmlCursorsForTenant(int $tenantId, string $reason): int
    {
        $blocked = 0;
        TenantDistributionCursor::query()
            ->where('tenant_id', $tenantId)
            ->whereNot('status', SyncCursorStatus::Blocked)
            ->each(function (TenantDistributionCursor $cursor) use ($reason, &$blocked): void {
                $cursor->status = SyncCursorStatus::Blocked;
                $cursor->last_error = mb_substr($reason, 0, 500);
                $cursor->save();
                $blocked++;
            });

        return $blocked;
    }

    /**
     * Exposto para orquestração pós-commit (ex.: troca de CNPJ).
     */
    public function deleteVaultObject(int $credentialId, string $objectId): void
    {
        $this->invalidateSupersededObject($credentialId, $objectId);
    }

    private function invalidateSupersededObject(int $credentialId, string $objectId): void
    {
        try {
            $this->store->delete($objectId);
        } catch (Throwable $e) {
            report(new RuntimeException(
                "Falha ao invalidar vault de credencial de escritório supersedida #{$credentialId}.",
                0,
                $e,
            ));
        }
    }
}

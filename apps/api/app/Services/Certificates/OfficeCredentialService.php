<?php

namespace App\Services\Certificates;

use App\Contracts\PfxReaderInterface;
use App\Contracts\SecureObjectStore;
use App\Domain\Cnpj;
use App\Enums\CredentialStatus;
use App\Enums\FiscalProfile;
use App\Enums\OfficeCredentialPurpose;
use App\Enums\SyncCursorStatus;
use App\Models\Office;
use App\Models\OfficeCredential;
use App\Models\OfficeCredentialPurposeLink;
use App\Models\OfficeDistributionCursor;
use App\Models\OfficeFiscalIdentity;
use App\Models\OfficeInstitutionalProfile;
use App\Services\Integra\OfficeSerproOnboardingService;
use App\Support\CurrentOffice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Ciclo de vida do A1 do escritório.
 *
 * Preferencial: credencial canônica CANONICAL_ECNPJ_A1 + vínculos de finalidade
 * (SERPRO_TERM_SIGNING, NFE_AUTXML_DISTDFE) sem duplicar o segredo.
 *
 * Legado: activate por OfficeFiscalIdentity + purpose NFE_AUTXML_DISTDFE.
 * Nunca materializa PEM em disco; PFX só em memória via vault.
 */
final class OfficeCredentialService
{
    /** Finalidades vinculadas automaticamente ao A1 canônico. */
    public const DEFAULT_PURPOSE_LINKS = [
        OfficeCredentialPurpose::SerproTermSigning,
        OfficeCredentialPurpose::NfeAutXmlDistDfe,
    ];

    public function __construct(
        private readonly SecureObjectStore $store,
        private readonly PfxReaderInterface $pfxReader,
        private readonly CurrentOffice $currentOffice,
        private readonly OfficeSerproOnboardingService $onboarding,
    ) {}

    /**
     * Ativa/substitui a credencial canônica e-CNPJ A1 do CurrentOffice.
     * Validate-before-cutover: falha de validação não altera a anterior.
     *
     * @param  list<OfficeCredentialPurpose>|null  $linkPurposes
     */
    public function activateCanonical(
        string $pfxBinary,
        string $password,
        ?int $actorUserId = null,
        ?array $linkPurposes = null,
    ): OfficeCredential {
        $office = $this->currentOffice->office();
        $profile = OfficeInstitutionalProfile::query()
            ->where('office_id', $office->id)
            ->first();

        if ($profile === null || $profile->cnpj === null || $profile->cnpj === '') {
            throw new RuntimeException(
                'Cadastre o CNPJ do perfil institucional antes do certificado A1.'
            );
        }

        // Validação completa ANTES de qualquer mutação (validate-before-cutover).
        $meta = $this->validateCanonicalPfx($pfxBinary, $password, $profile->cnpj);

        return $this->cutoverCanonical($office, $meta, $actorUserId, $linkPurposes);
    }

    /**
     * Substitui o A1 canônico (mesmo fluxo de activate; nome semântico para API).
     *
     * @param  list<OfficeCredentialPurpose>|null  $linkPurposes
     */
    public function replaceCanonical(
        string $pfxBinary,
        string $password,
        ?int $actorUserId = null,
        ?array $linkPurposes = null,
    ): OfficeCredential {
        return $this->activateCanonical($pfxBinary, $password, $actorUserId, $linkPurposes);
    }

    /**
     * Remoção confirmada do A1 canônico: revoga vínculos, bloqueia finalidades e dispara reonboarding.
     */
    public function removeCanonical(
        bool $confirmed,
        ?int $actorUserId = null,
        string $reason = 'Removida pelo administrador.',
    ): ?OfficeCredential {
        if (! $confirmed) {
            throw new RuntimeException(
                'A remoção do certificado A1 exige confirmação (confirm=true).'
            );
        }

        $credential = $this->activeCanonicalForCurrentOffice();
        if ($credential === null) {
            return null;
        }

        $this->revokeCanonical($credential, $reason, removeVault: true, triggerReonboarding: true, actorUserId: $actorUserId);

        return $credential->fresh();
    }

    public function activeCanonicalForCurrentOffice(): ?OfficeCredential
    {
        return $this->activeCanonical($this->currentOffice->office()->id);
    }

    public function activeCanonical(int $officeId): ?OfficeCredential
    {
        return OfficeCredential::query()
            ->where('office_id', $officeId)
            ->where('purpose', OfficeCredentialPurpose::CanonicalECnpjA1->value)
            ->where('status', CredentialStatus::Active)
            ->first();
    }

    /**
     * Resolve a credencial canônica ativa por finalidade (vínculo).
     */
    public function activeForPurpose(int $officeId, OfficeCredentialPurpose $purpose): ?OfficeCredential
    {
        if ($purpose === OfficeCredentialPurpose::CanonicalECnpjA1) {
            return $this->activeCanonical($officeId);
        }

        $link = OfficeCredentialPurposeLink::query()
            ->where('office_id', $officeId)
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

    /**
     * Legado: A1 por identidade fiscal + finalidade NFE_AUTXML_DISTDFE.
     * Mantido para compatibilidade; novos fluxos usam activateCanonical.
     */
    public function activate(
        OfficeFiscalIdentity $identity,
        string $pfxBinary,
        string $password,
        OfficeCredentialPurpose $purpose = OfficeCredentialPurpose::NfeAutXmlDistDfe,
    ): OfficeCredential {
        $officeId = $this->currentOffice->office()->id;
        if ($identity->office_id !== $officeId) {
            abort(404);
        }

        $meta = $this->pfxReader->read($pfxBinary, $password);
        $holder = Cnpj::parse($meta['cnpj']);

        // RV 593: raiz do certificado deve coincidir com a identidade do escritório.
        if ($holder->root() !== $identity->root_cnpj) {
            throw new RuntimeException(
                'A raiz do CNPJ do certificado diverge da identidade fiscal do escritório (equivalente à RV 593).'
            );
        }

        $payload = json_encode([
            'pfx' => base64_encode($meta['pfx']),
            'password' => $meta['password'],
        ], JSON_THROW_ON_ERROR);

        $aad = [
            'office_id' => $officeId,
            'office_fiscal_identity_id' => $identity->id,
            'purpose' => $purpose->value,
            'fingerprint' => $meta['fingerprint_sha256'],
        ];

        $objectId = $this->store->put($payload, $aad);
        $superseded = [];

        try {
            $credential = DB::transaction(function () use (
                $identity,
                $meta,
                $objectId,
                $officeId,
                $holder,
                $purpose,
                &$superseded,
            ): OfficeCredential {
                OfficeFiscalIdentity::query()->whereKey($identity->id)->lockForUpdate()->firstOrFail();

                $previous = OfficeCredential::query()
                    ->where('office_fiscal_identity_id', $identity->id)
                    ->where('purpose', $purpose->value)
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
                }

                return OfficeCredential::query()->create([
                    'office_id' => $officeId,
                    'office_fiscal_identity_id' => $identity->id,
                    'purpose' => $purpose,
                    'status' => CredentialStatus::Active,
                    'subject_name' => $meta['subject_name'],
                    'holder_cnpj' => $holder->value(),
                    'fingerprint_sha256' => $meta['fingerprint_sha256'],
                    'valid_from' => $meta['valid_from'],
                    'valid_to' => $meta['valid_to'],
                    'vault_object_id' => $objectId,
                    'activated_at' => now(),
                ]);
            });
        } catch (Throwable $e) {
            try {
                $this->store->delete($objectId);
            } catch (Throwable $cleanupError) {
                report(new RuntimeException('Falha ao compensar objeto de credencial do escritório.', 0, $cleanupError));
            }

            throw $e;
        }

        foreach ($superseded as $old) {
            $this->invalidateSupersededObject($old['id'], $old['object_id']);
        }

        return $credential;
    }

    public function activeFor(
        OfficeFiscalIdentity $identity,
        OfficeCredentialPurpose $purpose = OfficeCredentialPurpose::NfeAutXmlDistDfe,
    ): ?OfficeCredential {
        // Preferir vínculo canônico quando o purpose é de link.
        if ($purpose->isPurposeLink()) {
            $viaLink = $this->activeForPurpose((int) $identity->office_id, $purpose);
            if ($viaLink !== null) {
                return $viaLink;
            }
        }

        return OfficeCredential::query()
            ->where('office_fiscal_identity_id', $identity->id)
            ->where('purpose', $purpose->value)
            ->where('status', CredentialStatus::Active)
            ->first();
    }

    public function revoke(OfficeCredential $credential, string $reason = 'Revogada pelo administrador.'): void
    {
        $officeId = $this->currentOffice->office()->id;
        if ($credential->office_id !== $officeId) {
            abort(404);
        }

        if ($credential->isCanonical()) {
            $this->revokeCanonical($credential, $reason, removeVault: true, triggerReonboarding: true);

            return;
        }

        if ($credential->status === CredentialStatus::Revoked) {
            return;
        }

        $credential->status = CredentialStatus::Revoked;
        $credential->superseded_at = now();
        $credential->save();

        if ($credential->office_fiscal_identity_id !== null) {
            $this->blockAutXmlCursorsForIdentity(
                (int) $credential->office_fiscal_identity_id,
                $reason,
            );
        }
    }

    public function revokeCanonical(
        OfficeCredential $credential,
        string $reason = 'Revogada pelo administrador.',
        bool $removeVault = true,
        bool $triggerReonboarding = true,
        ?int $actorUserId = null,
    ): void {
        if (! $credential->isCanonical()) {
            throw new RuntimeException('Credencial não é canônica.');
        }

        $officeId = (int) $credential->office_id;

        DB::transaction(function () use ($credential, $officeId): void {
            $locked = OfficeCredential::query()->whereKey($credential->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CredentialStatus::Revoked) {
                $locked->status = CredentialStatus::Revoked;
                $locked->superseded_at = now();
                $locked->save();
            }

            OfficeCredentialPurposeLink::query()
                ->where('office_id', $officeId)
                ->where('office_credential_id', $locked->id)
                ->where('status', CredentialStatus::Active)
                ->lockForUpdate()
                ->get()
                ->each(function (OfficeCredentialPurposeLink $link): void {
                    $link->status = CredentialStatus::Revoked;
                    $link->revoked_at = now();
                    $link->save();
                });
        });

        // Bloqueia cursores autXML do office (qualquer identidade).
        $this->blockAutXmlCursorsForOffice($officeId, $reason);

        if ($removeVault && $credential->vault_object_id) {
            $this->invalidateSupersededObject((int) $credential->id, (string) $credential->vault_object_id);
        }

        if ($triggerReonboarding) {
            $office = Office::query()->find($officeId);
            if ($office !== null) {
                foreach ([FiscalProfile::configured()->serproEnvironment()] as $env) {
                    $this->onboarding->reactToProfileOrCredentialChange(
                        $office,
                        $env,
                        'a1_removed',
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
    public function loadPfxMaterial(OfficeCredential $credential): ?array
    {
        if (! $credential->status->isUsable()) {
            return null;
        }

        $allowed = [
            OfficeCredentialPurpose::NfeAutXmlDistDfe,
            OfficeCredentialPurpose::SerproTermSigning,
            OfficeCredentialPurpose::CanonicalECnpjA1,
        ];
        if (! in_array($credential->purpose, $allowed, true)) {
            return null;
        }

        if ($credential->valid_to->isPast()) {
            $credential->status = CredentialStatus::Expired;
            $credential->save();
            if ($credential->isCanonical()) {
                $this->blockAutXmlCursorsForOffice((int) $credential->office_id, 'Credencial A1 do escritório expirada.');
            } elseif ($credential->office_fiscal_identity_id !== null) {
                $this->blockAutXmlCursorsForIdentity(
                    (int) $credential->office_fiscal_identity_id,
                    'Credencial A1 do escritório expirada.',
                );
            }

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

        OfficeCredential::query()
            ->where('status', CredentialStatus::Active)
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($now, &$credentialsUpdated, &$cursorsBlocked): void {
                foreach ($rows as $credential) {
                    /** @var OfficeCredential $credential */
                    if ($credential->valid_to->isPast()) {
                        $credential->status = CredentialStatus::Expired;
                        $credential->save();
                        $credentialsUpdated++;
                        if ($credential->isCanonical()) {
                            $cursorsBlocked += $this->blockAutXmlCursorsForOffice(
                                (int) $credential->office_id,
                                'Credencial A1 do escritório expirada.',
                            );
                        } elseif ($credential->office_fiscal_identity_id !== null) {
                            $cursorsBlocked += $this->blockAutXmlCursorsForIdentity(
                                (int) $credential->office_fiscal_identity_id,
                                'Credencial A1 do escritório expirada.',
                            );
                        }

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
     * Alertas de painel deduplicados para a credencial canônica ativa.
     *
     * @return list<array{window_days: int, code: string, message: string}>
     */
    public function panelExpiryAlerts(?OfficeCredential $credential = null): array
    {
        $credential ??= $this->activeCanonicalForCurrentOffice();
        if ($credential === null || ! $credential->status->isUsable()) {
            return [];
        }

        $alerts = [];
        if ($credential->expires_alert_1) {
            $alerts[] = [
                'window_days' => 1,
                'code' => 'A1_EXPIRES_1D',
                'message' => 'O certificado A1 do escritório vence em até 1 dia.',
            ];
        } elseif ($credential->expires_alert_7) {
            $alerts[] = [
                'window_days' => 7,
                'code' => 'A1_EXPIRES_7D',
                'message' => 'O certificado A1 do escritório vence em até 7 dias.',
            ];
        } elseif ($credential->expires_alert_30) {
            $alerts[] = [
                'window_days' => 30,
                'code' => 'A1_EXPIRES_30D',
                'message' => 'O certificado A1 do escritório vence em até 30 dias.',
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
    private function validateCanonicalPfx(string $pfxBinary, string $password, string $expectedCnpj): array
    {
        if ($pfxBinary === '') {
            throw new RuntimeException('Arquivo PFX vazio.');
        }

        try {
            $meta = $this->pfxReader->read($pfxBinary, $password);
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException('Não foi possível validar o certificado A1.', 0, $e);
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
     * @param  list<OfficeCredentialPurpose>|null  $linkPurposes
     */
    private function cutoverCanonical(
        Office $office,
        array $meta,
        ?int $actorUserId,
        ?array $linkPurposes,
    ): OfficeCredential {
        $officeId = $office->id;
        $holder = Cnpj::parse($meta['cnpj']);
        $purpose = OfficeCredentialPurpose::CanonicalECnpjA1;
        $linkPurposes ??= self::DEFAULT_PURPOSE_LINKS;

        $payload = json_encode([
            'pfx' => base64_encode($meta['pfx']),
            'password' => $meta['password'],
        ], JSON_THROW_ON_ERROR);

        $aad = [
            'office_id' => $officeId,
            'purpose' => $purpose->value,
            'fingerprint' => $meta['fingerprint_sha256'],
        ];

        $objectId = $this->store->put($payload, $aad);
        $superseded = [];

        try {
            $credential = DB::transaction(function () use (
                $meta,
                $objectId,
                $officeId,
                $holder,
                $purpose,
                $linkPurposes,
                $actorUserId,
                &$superseded,
            ): OfficeCredential {
                // Serializa cutover concorrente por office.
                Office::query()->whereKey($officeId)->lockForUpdate()->firstOrFail();

                $previous = OfficeCredential::query()
                    ->where('office_id', $officeId)
                    ->where('purpose', $purpose->value)
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

                    OfficeCredentialPurposeLink::query()
                        ->where('office_credential_id', $old->id)
                        ->where('status', CredentialStatus::Active)
                        ->lockForUpdate()
                        ->get()
                        ->each(function (OfficeCredentialPurposeLink $link): void {
                            $link->status = CredentialStatus::Revoked;
                            $link->revoked_at = now();
                            $link->save();
                        });
                }

                $created = OfficeCredential::query()->create([
                    'office_id' => $officeId,
                    'office_fiscal_identity_id' => null,
                    'purpose' => $purpose,
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
                    if (! $linkPurpose instanceof OfficeCredentialPurpose || ! $linkPurpose->isPurposeLink()) {
                        continue;
                    }

                    // Revoga vínculo ativo anterior da mesma finalidade (outro credential).
                    OfficeCredentialPurposeLink::query()
                        ->where('office_id', $officeId)
                        ->where('purpose', $linkPurpose->value)
                        ->where('status', CredentialStatus::Active)
                        ->lockForUpdate()
                        ->get()
                        ->each(function (OfficeCredentialPurposeLink $link): void {
                            $link->status = CredentialStatus::Revoked;
                            $link->revoked_at = now();
                            $link->save();
                        });

                    OfficeCredentialPurposeLink::query()->create([
                        'office_id' => $officeId,
                        'office_credential_id' => $created->id,
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
                report(new RuntimeException('Falha ao compensar objeto de credencial canônica.', 0, $cleanupError));
            }

            throw $e;
        }

        foreach ($superseded as $old) {
            $this->invalidateSupersededObject($old['id'], $old['object_id']);
        }

        // Reonboarding das finalidades derivadas (Termo/token).
        foreach ([FiscalProfile::configured()->serproEnvironment()] as $env) {
            $this->onboarding->reactToProfileOrCredentialChange(
                $office,
                $env,
                'a1_replaced',
                $actorUserId,
            );
        }

        return $credential;
    }

    /**
     * @return array<string, mixed>
     */
    private function vaultAad(OfficeCredential $credential): array
    {
        if ($credential->isCanonical()) {
            return [
                'office_id' => $credential->office_id,
                'purpose' => $credential->purpose->value,
                'fingerprint' => $credential->fingerprint_sha256,
            ];
        }

        return [
            'office_id' => $credential->office_id,
            'office_fiscal_identity_id' => $credential->office_fiscal_identity_id,
            'purpose' => $credential->purpose->value,
            'fingerprint' => $credential->fingerprint_sha256,
        ];
    }

    private function blockAutXmlCursorsForIdentity(int $identityId, string $reason): int
    {
        $blocked = 0;
        OfficeDistributionCursor::query()
            ->where('office_fiscal_identity_id', $identityId)
            ->whereNot('status', SyncCursorStatus::Blocked)
            ->each(function (OfficeDistributionCursor $cursor) use ($reason, &$blocked): void {
                $cursor->status = SyncCursorStatus::Blocked;
                $cursor->last_error = mb_substr($reason, 0, 500);
                $cursor->save();
                $blocked++;
            });

        return $blocked;
    }

    private function blockAutXmlCursorsForOffice(int $officeId, string $reason): int
    {
        $blocked = 0;
        OfficeDistributionCursor::query()
            ->where('office_id', $officeId)
            ->whereNot('status', SyncCursorStatus::Blocked)
            ->each(function (OfficeDistributionCursor $cursor) use ($reason, &$blocked): void {
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

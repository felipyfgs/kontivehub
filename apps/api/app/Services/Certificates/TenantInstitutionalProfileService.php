<?php

namespace App\Services\Certificates;

use App\Domain\Cnpj;
use App\Enums\CredentialStatus;
use App\Enums\FiscalProfile;
use App\Models\Tenant;
use App\Models\TenantCredential;
use App\Models\TenantCredentialPurposeLink;
use App\Models\TenantInstitutionalProfile;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\TenantSerproOnboardingService;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Perfil institucional único do escritório (CNPJ, razão social, e-mail, telefone).
 * Escopo sempre via CurrentTenant — nunca tenant_id do client HTTP.
 */
final class TenantInstitutionalProfileService
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TenantCredentialService $credentials,
        private readonly TenantSerproOnboardingService $onboarding,
        private readonly AuditLogger $audit,
    ) {}

    public function forCurrentTenant(): TenantInstitutionalProfile
    {
        $tenant = $this->currentTenant->tenant();

        return $this->forTenant($tenant);
    }

    public function forTenant(Tenant $tenant): TenantInstitutionalProfile
    {
        $profile = TenantInstitutionalProfile::query()
            ->where('tenant_id', $tenant->id)
            ->first();

        if ($profile !== null) {
            return $profile;
        }

        return TenantInstitutionalProfile::query()->create([
            'tenant_id' => $tenant->id,
            'cnpj' => null,
            'legal_name' => $tenant->name,
            'institutional_email' => null,
            'institutional_phone' => null,
        ]);
    }

    /**
     * Atualiza campos institucionais do CurrentTenant.
     * Mudança de CNPJ exige confirmação forte e invalida artefatos derivados.
     *
     * @param  array{
     *   cnpj?: string|null,
     *   legal_name?: string|null,
     *   institutional_email?: string|null,
     *   institutional_phone?: string|null,
     *   confirm_cnpj_change?: bool
     * }  $data
     * @return array{profile: TenantInstitutionalProfile, cnpj_changed: bool, invalidated: array<string, mixed>}
     */
    public function update(array $data, ?int $actorUserId = null): array
    {
        $tenant = $this->currentTenant->tenant();
        $profile = $this->forTenant($tenant);

        $confirmCnpjChange = (bool) ($data['confirm_cnpj_change'] ?? false);
        unset($data['confirm_cnpj_change'], $data['tenant_id']);

        $newCnpj = array_key_exists('cnpj', $data)
            ? $this->normalizeOptionalCnpj($data['cnpj'])
            : $profile->cnpj;

        $cnpjChanging = $newCnpj !== null
            && $profile->cnpj !== null
            && $newCnpj !== $profile->cnpj;

        $cnpjFirstSet = $newCnpj !== null && ($profile->cnpj === null || $profile->cnpj === '');

        if ($cnpjChanging && ! $confirmCnpjChange) {
            throw new RuntimeException(
                'A troca de CNPJ exige confirmação forte (confirm_cnpj_change=true) e invalida certificado, Termo e tokens derivados.'
            );
        }

        $before = [
            'cnpj' => $profile->cnpj,
            'legal_name' => $profile->legal_name,
            'institutional_email' => $profile->institutional_email,
            'institutional_phone' => $profile->institutional_phone,
        ];

        $invalidated = [
            'credentials_revoked' => 0,
            'purpose_links_revoked' => 0,
            'reonboarding_triggered' => false,
        ];
        /** @var list<array{id: int, object_id: string}> $vaultsToDelete */
        $vaultsToDelete = [];

        $result = DB::transaction(function () use (
            $profile,
            $tenant,
            $data,
            $newCnpj,
            $cnpjChanging,
            $cnpjFirstSet,
            &$invalidated,
            &$vaultsToDelete,
        ): TenantInstitutionalProfile {
            $locked = TenantInstitutionalProfile::query()
                ->whereKey($profile->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (array_key_exists('legal_name', $data)) {
                $locked->legal_name = $this->nullableTrim($data['legal_name']);
            }
            if (array_key_exists('institutional_email', $data)) {
                $locked->institutional_email = $this->nullableTrim($data['institutional_email']);
            }
            if (array_key_exists('institutional_phone', $data)) {
                $locked->institutional_phone = $this->nullableTrim($data['institutional_phone']);
            }
            if (array_key_exists('cnpj', $data) || $cnpjFirstSet) {
                $locked->cnpj = $newCnpj;
            }

            $locked->save();

            if ($cnpjChanging) {
                $invalidated = $this->invalidateMismatchedCredentials(
                    $tenant,
                    $newCnpj,
                    $vaultsToDelete,
                );
            }

            return $locked->refresh();
        });

        // Vault só após commit SQL (não reverte se falhar — metadados já revogados).
        foreach ($vaultsToDelete as $old) {
            $this->credentials->deleteVaultObject((int) $old['id'], (string) $old['object_id']);
        }

        if ($cnpjChanging) {
            foreach ([FiscalProfile::configured()->serproEnvironment()] as $env) {
                $this->onboarding->reactToProfileOrCredentialChange(
                    $tenant,
                    $env,
                    'cnpj_changed',
                    $actorUserId,
                );
            }
            $invalidated['reonboarding_triggered'] = true;
        }

        $changes = [];
        foreach (['cnpj', 'legal_name', 'institutional_email', 'institutional_phone'] as $field) {
            if ($before[$field] !== $result->{$field}) {
                $changes[$field] = [
                    'from' => $before[$field],
                    'to' => $result->{$field},
                ];
            }
        }

        $this->audit->record('tenant.institutional_profile.update', 'SUCCESS', $result, [
            'changes' => $changes,
            'cnpj_changed' => $cnpjChanging,
            'invalidated' => $invalidated,
        ], $actorUserId, $tenant->id);

        return [
            'profile' => $result,
            'cnpj_changed' => $cnpjChanging,
            'invalidated' => $invalidated,
        ];
    }

    /**
     * @param  list<array{id: int, object_id: string}>  $vaultsToDelete
     * @return array{credentials_revoked: int, purpose_links_revoked: int, reonboarding_triggered: bool}
     */
    private function invalidateMismatchedCredentials(
        Tenant $tenant,
        string $newCnpj,
        array &$vaultsToDelete,
    ): array {
        $credentialsRevoked = 0;
        $linksRevoked = 0;

        $activeCredentials = TenantCredential::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', CredentialStatus::Active)
            ->lockForUpdate()
            ->get();

        foreach ($activeCredentials as $credential) {
            if ($credential->holder_cnpj !== $newCnpj) {
                $vaultsToDelete[] = [
                    'id' => (int) $credential->id,
                    'object_id' => (string) $credential->vault_object_id,
                ];
                $credential->status = CredentialStatus::Revoked;
                $credential->superseded_at = now();
                $credential->save();
                $credentialsRevoked++;
            }
        }

        $links = TenantCredentialPurposeLink::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', CredentialStatus::Active)
            ->lockForUpdate()
            ->get();

        foreach ($links as $link) {
            $cred = $link->credential;
            if ($cred === null || $cred->holder_cnpj !== $newCnpj || ! $cred->status->isUsable()) {
                $link->status = CredentialStatus::Revoked;
                $link->revoked_at = now();
                $link->save();
                $linksRevoked++;
            }
        }

        return [
            'credentials_revoked' => $credentialsRevoked,
            'purpose_links_revoked' => $linksRevoked,
            'reonboarding_triggered' => false,
        ];
    }

    private function normalizeOptionalCnpj(mixed $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw)) {
            throw new InvalidArgumentException('CNPJ inválido.');
        }

        try {
            return Cnpj::parse($raw)->value();
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('CNPJ institucional inválido: '.$e->getMessage(), 0, $e);
        }
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}

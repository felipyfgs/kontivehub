<?php

namespace App\Services\Certificates;

use App\Domain\Cnpj;
use App\Enums\CredentialStatus;
use App\Enums\FiscalProfile;
use App\Enums\OfficeCredentialPurpose;
use App\Models\Office;
use App\Models\OfficeCredential;
use App\Models\OfficeCredentialPurposeLink;
use App\Models\OfficeInstitutionalProfile;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\OfficeSerproOnboardingService;
use App\Support\CurrentOffice;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Perfil institucional único do escritório (CNPJ, razão social, e-mail, telefone).
 * Escopo sempre via CurrentOffice — nunca office_id do client HTTP.
 */
final class OfficeInstitutionalProfileService
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly OfficeCredentialService $credentials,
        private readonly OfficeSerproOnboardingService $onboarding,
        private readonly AuditLogger $audit,
    ) {}

    public function forCurrentOffice(): OfficeInstitutionalProfile
    {
        $office = $this->currentOffice->office();

        return $this->forOffice($office);
    }

    public function forOffice(Office $office): OfficeInstitutionalProfile
    {
        $profile = OfficeInstitutionalProfile::query()
            ->where('office_id', $office->id)
            ->first();

        if ($profile !== null) {
            return $profile;
        }

        return OfficeInstitutionalProfile::query()->create([
            'office_id' => $office->id,
            'cnpj' => null,
            'legal_name' => $office->name,
            'institutional_email' => null,
            'institutional_phone' => null,
        ]);
    }

    /**
     * Atualiza campos institucionais do CurrentOffice.
     * Mudança de CNPJ exige confirmação forte e invalida artefatos derivados.
     *
     * @param  array{
     *   cnpj?: string|null,
     *   legal_name?: string|null,
     *   institutional_email?: string|null,
     *   institutional_phone?: string|null,
     *   confirm_cnpj_change?: bool
     * }  $data
     * @return array{profile: OfficeInstitutionalProfile, cnpj_changed: bool, invalidated: array<string, mixed>}
     */
    public function update(array $data, ?int $actorUserId = null): array
    {
        $office = $this->currentOffice->office();
        $profile = $this->forOffice($office);

        $confirmCnpjChange = (bool) ($data['confirm_cnpj_change'] ?? false);
        unset($data['confirm_cnpj_change'], $data['office_id']);

        $newCnpj = array_key_exists('cnpj', $data)
            ? $this->normalizeOptionalCnpj($data['cnpj'])
            : $profile->cnpj;

        $cnpjChanging = $newCnpj !== null
            && $profile->cnpj !== null
            && $newCnpj !== $profile->cnpj;

        $cnpjFirstSet = $newCnpj !== null && ($profile->cnpj === null || $profile->cnpj === '');

        if ($cnpjChanging && ! $confirmCnpjChange) {
            throw new RuntimeException(
                'A troca de CNPJ exige confirmação forte (confirm_cnpj_change=true) e invalida A1, Termo e tokens derivados.'
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
            $office,
            $data,
            $newCnpj,
            $cnpjChanging,
            $cnpjFirstSet,
            &$invalidated,
            &$vaultsToDelete,
        ): OfficeInstitutionalProfile {
            $locked = OfficeInstitutionalProfile::query()
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
                $invalidated = $this->invalidateIncompatibleArtifactsInTransaction(
                    $office,
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
                    $office,
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

        $this->audit->record('office.institutional_profile.update', 'SUCCESS', $result, [
            'changes' => $changes,
            'cnpj_changed' => $cnpjChanging,
            'invalidated' => $invalidated,
        ], $actorUserId, $office->id);

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
    private function invalidateIncompatibleArtifactsInTransaction(
        Office $office,
        string $newCnpj,
        array &$vaultsToDelete,
    ): array {
        $credentialsRevoked = 0;
        $linksRevoked = 0;

        $activeCanonical = OfficeCredential::query()
            ->where('office_id', $office->id)
            ->where('purpose', OfficeCredentialPurpose::CanonicalECnpjA1->value)
            ->where('status', CredentialStatus::Active)
            ->lockForUpdate()
            ->get();

        foreach ($activeCanonical as $credential) {
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

        $legacy = OfficeCredential::query()
            ->where('office_id', $office->id)
            ->whereIn('purpose', [
                OfficeCredentialPurpose::NfeAutXmlDistDfe->value,
                OfficeCredentialPurpose::SerproTermSigning->value,
            ])
            ->where('status', CredentialStatus::Active)
            ->lockForUpdate()
            ->get();

        foreach ($legacy as $credential) {
            if ($credential->holder_cnpj !== $newCnpj) {
                $credential->status = CredentialStatus::Revoked;
                $credential->superseded_at = now();
                $credential->save();
                $credentialsRevoked++;
            }
        }

        $links = OfficeCredentialPurposeLink::query()
            ->where('office_id', $office->id)
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

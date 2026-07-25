<?php

namespace App\Services\Certificates;

use App\Enums\FiscalProfile;
use App\Enums\OfficeCredentialPurpose;
use App\Models\Office;
use App\Models\OfficeTechnicalConsent;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\OfficeSerproOnboardingService;
use App\Support\CurrentOffice;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Consentimento técnico versionado do escritório (uso do A1 e finalidades).
 * Histórico append-only; revogação marca revoked_at.
 */
final class OfficeTechnicalConsentService
{
    /** Finalidades apresentadas na versão unificada vigente. */
    public const DEFAULT_PURPOSES = [
        OfficeCredentialPurpose::CanonicalECnpjA1->value,
        OfficeCredentialPurpose::SerproTermSigning->value,
        OfficeCredentialPurpose::NfeAutXmlDistDfe->value,
    ];

    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly OfficeSerproOnboardingService $onboarding,
        private readonly AuditLogger $audit,
    ) {}

    public function activeForCurrentOffice(?string $versionCode = null): ?OfficeTechnicalConsent
    {
        $officeId = $this->currentOffice->office()->id;
        $versionCode ??= OfficeTechnicalConsent::VERSION_UNIFIED_A1_V1;

        return OfficeTechnicalConsent::query()
            ->where('office_id', $officeId)
            ->where('version_code', $versionCode)
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->first();
    }

    public function currentStatus(): array
    {
        $version = OfficeTechnicalConsent::VERSION_UNIFIED_A1_V1;
        $active = $this->activeForCurrentOffice($version);

        return [
            'version_code' => $version,
            'purposes_presented' => self::DEFAULT_PURPOSES,
            'active_consent' => $active?->toPublicArray(),
            'requires_consent' => $active === null,
        ];
    }

    /**
     * Aceita a versão vigente (checkbox explícito no client → accepted=true).
     */
    public function grant(
        bool $accepted,
        ?int $actorUserId = null,
        ?string $versionCode = null,
        ?array $purposesPresented = null,
    ): OfficeTechnicalConsent {
        if (! $accepted) {
            throw new RuntimeException('O consentimento técnico exige aceitação explícita (accepted=true).');
        }

        $office = $this->currentOffice->office();
        $actorUserId ??= auth()->id();
        if ($actorUserId === null) {
            throw new RuntimeException('Ator do consentimento ausente.');
        }

        $versionCode ??= OfficeTechnicalConsent::VERSION_UNIFIED_A1_V1;
        $purposes = $purposesPresented ?? self::DEFAULT_PURPOSES;
        $payloadSha = hash('sha256', $versionCode.'|'.implode(',', $purposes));

        $consent = DB::transaction(function () use ($office, $versionCode, $purposes, $actorUserId, $payloadSha): OfficeTechnicalConsent {
            // Nova concordância da mesma versão: revoga a ativa anterior (histórico preservado).
            OfficeTechnicalConsent::query()
                ->where('office_id', $office->id)
                ->where('version_code', $versionCode)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get()
                ->each(function (OfficeTechnicalConsent $prev): void {
                    $prev->revoked_at = now();
                    $prev->save();
                });

            return OfficeTechnicalConsent::query()->create([
                'office_id' => $office->id,
                'version_code' => $versionCode,
                'purposes_presented' => $purposes,
                'actor_user_id' => $actorUserId,
                'consented_at' => now(),
                'revoked_at' => null,
                'payload_sha256' => $payloadSha,
                'metadata' => null,
            ]);
        });

        $this->audit->record('office.technical_consent.grant', 'SUCCESS', $consent, [
            'version_code' => $consent->version_code,
            'purposes_presented' => $consent->purposes_presented,
            'payload_sha256' => $consent->payload_sha256,
        ], $actorUserId, $office->id);

        // Consentimento novo pode desbloquear onboarding (evaluate).
        foreach ([FiscalProfile::configured()->serproEnvironment()] as $env) {
            $this->onboarding->evaluateAndMaybeEnqueue($office, $env, $actorUserId);
        }

        return $consent;
    }

    public function revoke(?int $actorUserId = null, ?string $versionCode = null): ?OfficeTechnicalConsent
    {
        $office = $this->currentOffice->office();
        $versionCode ??= OfficeTechnicalConsent::VERSION_UNIFIED_A1_V1;
        $actorUserId ??= auth()->id();

        $active = $this->activeForCurrentOffice($versionCode);
        if ($active === null) {
            return null;
        }

        $active->revoked_at = now();
        $active->save();

        $this->audit->record('office.technical_consent.revoke', 'SUCCESS', $active, [
            'version_code' => $active->version_code,
        ], $actorUserId, $office->id);

        foreach ([FiscalProfile::configured()->serproEnvironment()] as $env) {
            $this->onboarding->reactToProfileOrCredentialChange(
                $office,
                $env,
                'consent_revoked',
                $actorUserId,
            );
        }

        return $active->refresh();
    }

    public function hasActiveConsent(Office $office, ?string $versionCode = null): bool
    {
        $versionCode ??= OfficeTechnicalConsent::VERSION_UNIFIED_A1_V1;

        return OfficeTechnicalConsent::query()
            ->where('office_id', $office->id)
            ->where('version_code', $versionCode)
            ->whereNull('revoked_at')
            ->exists();
    }
}

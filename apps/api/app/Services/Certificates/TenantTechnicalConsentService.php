<?php

namespace App\Services\Certificates;

use App\Enums\FiscalProfile;
use App\Enums\TenantCredentialPurpose;
use App\Models\Tenant;
use App\Models\TenantTechnicalConsent;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\TenantSerproOnboardingService;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Consentimento técnico versionado do escritório (uso do certificado e finalidades).
 * Histórico append-only; revogação marca revoked_at.
 */
final class TenantTechnicalConsentService
{
    /** Finalidades apresentadas na versão unificada vigente. */
    public const DEFAULT_PURPOSES = [
        'CERTIFICATE',
        TenantCredentialPurpose::SerproTermSigning->value,
        TenantCredentialPurpose::NfeAutXmlDistDfe->value,
    ];

    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TenantSerproOnboardingService $onboarding,
        private readonly AuditLogger $audit,
    ) {}

    public function activeForCurrentTenant(?string $versionCode = null): ?TenantTechnicalConsent
    {
        $tenantId = $this->currentTenant->tenant()->id;
        $versionCode ??= TenantTechnicalConsent::VERSION_CERTIFICATE_V1;

        return TenantTechnicalConsent::query()
            ->where('tenant_id', $tenantId)
            ->where('version_code', $versionCode)
            ->whereNull('revoked_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Aceita a versão vigente (checkbox explícito no client → accepted=true).
     */
    public function grant(
        bool $accepted,
        ?int $actorUserId = null,
        ?string $versionCode = null,
        ?array $purposesPresented = null,
    ): TenantTechnicalConsent {
        if (! $accepted) {
            throw new RuntimeException('O consentimento técnico exige aceitação explícita (accepted=true).');
        }

        $tenant = $this->currentTenant->tenant();
        $actorUserId ??= auth()->id();
        if ($actorUserId === null) {
            throw new RuntimeException('Ator do consentimento ausente.');
        }

        $versionCode ??= TenantTechnicalConsent::VERSION_CERTIFICATE_V1;
        $purposes = $purposesPresented ?? self::DEFAULT_PURPOSES;
        $payloadSha = hash('sha256', $versionCode.'|'.implode(',', $purposes));

        $consent = DB::transaction(function () use ($tenant, $versionCode, $purposes, $actorUserId, $payloadSha): TenantTechnicalConsent {
            // Nova concordância da mesma versão: revoga a ativa anterior (histórico preservado).
            TenantTechnicalConsent::query()
                ->where('tenant_id', $tenant->id)
                ->where('version_code', $versionCode)
                ->whereNull('revoked_at')
                ->lockForUpdate()
                ->get()
                ->each(function (TenantTechnicalConsent $prev): void {
                    $prev->revoked_at = now();
                    $prev->save();
                });

            return TenantTechnicalConsent::query()->create([
                'tenant_id' => $tenant->id,
                'version_code' => $versionCode,
                'purposes_presented' => $purposes,
                'actor_user_id' => $actorUserId,
                'consented_at' => now(),
                'revoked_at' => null,
                'payload_sha256' => $payloadSha,
                'metadata' => null,
            ]);
        });

        $this->audit->record('tenant.technical_consent.grant', 'SUCCESS', $consent, [
            'version_code' => $consent->version_code,
            'purposes_presented' => $consent->purposes_presented,
            'payload_sha256' => $consent->payload_sha256,
        ], $actorUserId, $tenant->id);

        // Consentimento novo pode desbloquear onboarding (evaluate).
        foreach ([FiscalProfile::configured()->serproEnvironment()] as $env) {
            $this->onboarding->evaluateAndMaybeEnqueue($tenant, $env, $actorUserId);
        }

        return $consent;
    }

    public function revoke(?int $actorUserId = null, ?string $versionCode = null): ?TenantTechnicalConsent
    {
        $tenant = $this->currentTenant->tenant();
        $versionCode ??= TenantTechnicalConsent::VERSION_CERTIFICATE_V1;
        $actorUserId ??= auth()->id();

        $active = $this->activeForCurrentTenant($versionCode);
        if ($active === null) {
            return null;
        }

        $active->revoked_at = now();
        $active->save();

        $this->audit->record('tenant.technical_consent.revoke', 'SUCCESS', $active, [
            'version_code' => $active->version_code,
        ], $actorUserId, $tenant->id);

        foreach ([FiscalProfile::configured()->serproEnvironment()] as $env) {
            $this->onboarding->reactToProfileOrCredentialChange(
                $tenant,
                $env,
                'consent_revoked',
                $actorUserId,
            );
        }

        return $active->refresh();
    }

    public function hasActiveConsent(Tenant $tenant, ?string $versionCode = null): bool
    {
        $versionCode ??= TenantTechnicalConsent::VERSION_CERTIFICATE_V1;

        return TenantTechnicalConsent::query()
            ->where('tenant_id', $tenant->id)
            ->where('version_code', $versionCode)
            ->whereNull('revoked_at')
            ->exists();
    }
}

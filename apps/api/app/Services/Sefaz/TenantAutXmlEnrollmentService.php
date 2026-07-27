<?php

namespace App\Services\Sefaz;

use App\Enums\TenantAutXmlEnrollmentStatus;
use App\Enums\TenantFiscalIdentityStatus;
use App\Models\Establishment;
use App\Models\TenantAutXmlEnrollment;
use App\Models\TenantDistributionCursor;
use App\Models\TenantFiscalIdentity;
use App\Models\User;
use App\Services\Certificates\TenantFiscalIdentityService;
use RuntimeException;

/**
 * Checklist autXML por estabelecimento — PENDING → CONFIRMED / INACTIVE.
 * Confirmação operacional exige stream ativado + quiet mínimo.
 */
final class TenantAutXmlEnrollmentService
{
    public function __construct(
        private readonly TenantFiscalIdentityService $identities,
        private readonly TenantDistributionCursorService $cursors,
    ) {}

    public function activeIdentity(): ?TenantFiscalIdentity
    {
        return $this->identities->activeForCurrentTenant();
    }

    public function cursorForIdentity(?TenantFiscalIdentity $identity = null): ?TenantDistributionCursor
    {
        $identity ??= $this->activeIdentity();
        if ($identity === null) {
            return null;
        }

        return TenantDistributionCursor::query()
            ->where('tenant_id', $identity->tenant_id)
            ->where('tenant_fiscal_identity_id', $identity->id)
            ->orderBy('id')
            ->first();
    }

    /**
     * Stream apto a confirmar enrollments: primeira distNSU registrada + quiet mínimo.
     */
    public function isStreamReadyForConfirm(?TenantDistributionCursor $cursor = null): bool
    {
        $cursor ??= $this->cursorForIdentity();
        if ($cursor === null || $cursor->activated_at === null) {
            return false;
        }

        $quietHours = max(0.0, (float) config('sefaz.autxml.quiet_hours_after_empty', 1));
        if ($quietHours <= 0) {
            return true;
        }

        return $cursor->activated_at->lte(now()->subHours($quietHours));
    }

    /**
     * @return array{
     *   stream_ready: bool,
     *   stream_reason: string|null,
     *   quiet_hours: float,
     *   activated_at: string|null,
     *   ready_at: string|null
     * }
     */
    public function streamGate(?TenantDistributionCursor $cursor = null): array
    {
        $cursor ??= $this->cursorForIdentity();
        $quietHours = max(0.0, (float) config('sefaz.autxml.quiet_hours_after_empty', 1));

        if ($cursor === null) {
            return [
                'stream_ready' => false,
                'stream_reason' => 'CURSOR_MISSING',
                'quiet_hours' => $quietHours,
                'activated_at' => null,
                'ready_at' => null,
            ];
        }

        if ($cursor->activated_at === null) {
            return [
                'stream_ready' => false,
                'stream_reason' => 'NOT_ACTIVATED',
                'quiet_hours' => $quietHours,
                'activated_at' => null,
                'ready_at' => null,
            ];
        }

        $readyAt = $cursor->activated_at->addHours($quietHours);
        $ready = $readyAt->lte(now());

        return [
            'stream_ready' => $ready,
            'stream_reason' => $ready ? null : 'QUIET_PENDING',
            'quiet_hours' => $quietHours,
            'activated_at' => $cursor->activated_at->toIso8601String(),
            'ready_at' => $readyAt->toIso8601String(),
        ];
    }

    /**
     * Checklist: estabelecimentos ativos do tenant + enrollment (ou ausente).
     *
     * @return list<array<string, mixed>>
     */
    public function checklistForTenant(int $tenantId): array
    {
        $identity = $this->activeIdentity();
        $enrollments = $identity === null
            ? collect()
            : TenantAutXmlEnrollment::query()
                ->where('tenant_id', $tenantId)
                ->where('tenant_fiscal_identity_id', $identity->id)
                ->get()
                ->keyBy('establishment_id');

        $establishments = Establishment::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('client:id,legal_name,display_name')
            ->orderBy('cnpj')
            ->get();

        $items = [];
        foreach ($establishments as $est) {
            /** @var TenantAutXmlEnrollment|null $enr */
            $enr = $enrollments->get($est->id);
            $items[] = $this->enrollmentPublic($est, $enr);
        }

        return $items;
    }

    public function ensurePending(Establishment $establishment): TenantAutXmlEnrollment
    {
        $identity = $this->requireActiveIdentity();

        if ((int) $establishment->tenant_id !== (int) $identity->tenant_id) {
            throw new RuntimeException('Estabelecimento não pertence ao escritório ativo.');
        }

        if (! $establishment->is_active) {
            throw new RuntimeException('Estabelecimento inativo não pode ser enrolled em autXML.');
        }

        $existing = TenantAutXmlEnrollment::query()
            ->where('tenant_id', $identity->tenant_id)
            ->where('tenant_fiscal_identity_id', $identity->id)
            ->where('establishment_id', $establishment->id)
            ->first();

        if ($existing !== null) {
            if ($existing->status === TenantAutXmlEnrollmentStatus::Inactive) {
                $existing->status = TenantAutXmlEnrollmentStatus::Pending;
                $existing->activated_at = null;
                $existing->confirmed_by = null;
                $existing->save();
            }

            return $existing->fresh() ?? $existing;
        }

        return TenantAutXmlEnrollment::query()->create([
            'tenant_id' => $identity->tenant_id,
            'tenant_fiscal_identity_id' => $identity->id,
            'establishment_id' => $establishment->id,
            'status' => TenantAutXmlEnrollmentStatus::Pending,
        ]);
    }

    public function confirm(TenantAutXmlEnrollment $enrollment, User $actor): TenantAutXmlEnrollment
    {
        if ($enrollment->status === TenantAutXmlEnrollmentStatus::Inactive) {
            throw new RuntimeException('Enrollment inativo — reative como PENDING antes de confirmar.');
        }

        if (! $this->isStreamReadyForConfirm($this->cursorForIdentity())) {
            throw new RuntimeException(
                'Confirmação bloqueada: ative o stream autXML (primeira distNSU) e aguarde o quiet mínimo de 1 hora.'
            );
        }

        $enrollment->status = TenantAutXmlEnrollmentStatus::Confirmed;
        $enrollment->activated_at = $enrollment->activated_at ?? now();
        $enrollment->confirmed_by = $actor->id;
        $enrollment->save();

        return $enrollment->fresh() ?? $enrollment;
    }

    public function inactivate(TenantAutXmlEnrollment $enrollment): TenantAutXmlEnrollment
    {
        $enrollment->status = TenantAutXmlEnrollmentStatus::Inactive;
        $enrollment->save();

        return $enrollment->fresh() ?? $enrollment;
    }

    /**
     * @return array<string, mixed>
     */
    public function enrollmentPublic(Establishment $est, ?TenantAutXmlEnrollment $enr): array
    {
        $client = $est->relationLoaded('client') ? $est->client : $est->client()->first();

        return [
            'id' => $enr?->id,
            'establishment_id' => $est->id,
            'establishment_cnpj' => $est->cnpj,
            'establishment_name' => $est->trade_name,
            'client_id' => $est->client_id,
            'client_name' => $client?->display_name ?: $client?->legal_name,
            'status' => $enr?->status->value ?? 'NONE',
            'activated_at' => $enr?->activated_at?->toIso8601String(),
            'first_seen_at' => $enr?->first_seen_at?->toIso8601String(),
            'last_seen_at' => $enr?->last_seen_at?->toIso8601String(),
            'observed' => $enr?->first_seen_at !== null,
            'channel_coverage' => 'NFE_55',
            'channel_coverage_label' => 'NF-e modelo 55 (autXML DistDFe)',
            'nfce_hint' => 'NFC-e modelo 65 não é capturada por este canal — use import XML/ZIP.',
        ];
    }

    private function requireActiveIdentity(): TenantFiscalIdentity
    {
        $identity = $this->activeIdentity();
        if ($identity === null || $identity->status !== TenantFiscalIdentityStatus::Active) {
            throw new RuntimeException('Cadastre a identidade fiscal do escritório antes do onboarding autXML.');
        }

        return $identity;
    }
}

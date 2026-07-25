<?php

namespace App\Services\Sefaz;

use App\Enums\OfficeAutXmlEnrollmentStatus;
use App\Enums\OfficeFiscalIdentityStatus;
use App\Models\Establishment;
use App\Models\OfficeAutXmlEnrollment;
use App\Models\OfficeDistributionCursor;
use App\Models\OfficeFiscalIdentity;
use App\Models\User;
use App\Services\Certificates\OfficeFiscalIdentityService;
use RuntimeException;

/**
 * Checklist autXML por estabelecimento — PENDING → CONFIRMED / INACTIVE.
 * Confirmação operacional exige stream ativado + quiet mínimo.
 */
final class OfficeAutXmlEnrollmentService
{
    public function __construct(
        private readonly OfficeFiscalIdentityService $identities,
        private readonly OfficeDistributionCursorService $cursors,
    ) {}

    public function activeIdentity(): ?OfficeFiscalIdentity
    {
        return $this->identities->activeForCurrentOffice();
    }

    public function cursorForIdentity(?OfficeFiscalIdentity $identity = null): ?OfficeDistributionCursor
    {
        $identity ??= $this->activeIdentity();
        if ($identity === null) {
            return null;
        }

        return OfficeDistributionCursor::query()
            ->where('office_id', $identity->office_id)
            ->where('office_fiscal_identity_id', $identity->id)
            ->orderBy('id')
            ->first();
    }

    /**
     * Stream apto a confirmar enrollments: primeira distNSU registrada + quiet mínimo.
     */
    public function isStreamReadyForConfirm(?OfficeDistributionCursor $cursor = null): bool
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
    public function streamGate(?OfficeDistributionCursor $cursor = null): array
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
     * Checklist: estabelecimentos ativos do office + enrollment (ou ausente).
     *
     * @return list<array<string, mixed>>
     */
    public function checklistForOffice(int $officeId): array
    {
        $identity = $this->activeIdentity();
        $enrollments = $identity === null
            ? collect()
            : OfficeAutXmlEnrollment::query()
                ->where('office_id', $officeId)
                ->where('office_fiscal_identity_id', $identity->id)
                ->get()
                ->keyBy('establishment_id');

        $establishments = Establishment::query()
            ->where('office_id', $officeId)
            ->where('is_active', true)
            ->with('client:id,legal_name,display_name')
            ->orderBy('cnpj')
            ->get();

        $items = [];
        foreach ($establishments as $est) {
            /** @var OfficeAutXmlEnrollment|null $enr */
            $enr = $enrollments->get($est->id);
            $items[] = $this->enrollmentPublic($est, $enr);
        }

        return $items;
    }

    public function ensurePending(Establishment $establishment): OfficeAutXmlEnrollment
    {
        $identity = $this->requireActiveIdentity();

        if ((int) $establishment->office_id !== (int) $identity->office_id) {
            throw new RuntimeException('Estabelecimento não pertence ao escritório ativo.');
        }

        if (! $establishment->is_active) {
            throw new RuntimeException('Estabelecimento inativo não pode ser enrolled em autXML.');
        }

        $existing = OfficeAutXmlEnrollment::query()
            ->where('office_id', $identity->office_id)
            ->where('office_fiscal_identity_id', $identity->id)
            ->where('establishment_id', $establishment->id)
            ->first();

        if ($existing !== null) {
            if ($existing->status === OfficeAutXmlEnrollmentStatus::Inactive) {
                $existing->status = OfficeAutXmlEnrollmentStatus::Pending;
                $existing->activated_at = null;
                $existing->confirmed_by = null;
                $existing->save();
            }

            return $existing->fresh() ?? $existing;
        }

        return OfficeAutXmlEnrollment::query()->create([
            'office_id' => $identity->office_id,
            'office_fiscal_identity_id' => $identity->id,
            'establishment_id' => $establishment->id,
            'status' => OfficeAutXmlEnrollmentStatus::Pending,
        ]);
    }

    public function confirm(OfficeAutXmlEnrollment $enrollment, User $actor): OfficeAutXmlEnrollment
    {
        if ($enrollment->status === OfficeAutXmlEnrollmentStatus::Inactive) {
            throw new RuntimeException('Enrollment inativo — reative como PENDING antes de confirmar.');
        }

        if (! $this->isStreamReadyForConfirm($this->cursorForIdentity())) {
            throw new RuntimeException(
                'Confirmação bloqueada: ative o stream autXML (primeira distNSU) e aguarde o quiet mínimo de 1 hora.'
            );
        }

        $enrollment->status = OfficeAutXmlEnrollmentStatus::Confirmed;
        $enrollment->activated_at = $enrollment->activated_at ?? now();
        $enrollment->confirmed_by = $actor->id;
        $enrollment->save();

        return $enrollment->fresh() ?? $enrollment;
    }

    public function inactivate(OfficeAutXmlEnrollment $enrollment): OfficeAutXmlEnrollment
    {
        $enrollment->status = OfficeAutXmlEnrollmentStatus::Inactive;
        $enrollment->save();

        return $enrollment->fresh() ?? $enrollment;
    }

    /**
     * @return array<string, mixed>
     */
    public function enrollmentPublic(Establishment $est, ?OfficeAutXmlEnrollment $enr): array
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

    private function requireActiveIdentity(): OfficeFiscalIdentity
    {
        $identity = $this->activeIdentity();
        if ($identity === null || $identity->status !== OfficeFiscalIdentityStatus::Active) {
            throw new RuntimeException('Cadastre a identidade fiscal do escritório antes do onboarding autXML.');
        }

        return $identity;
    }
}

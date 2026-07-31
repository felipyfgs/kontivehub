<?php

namespace App\Services\Sefaz;

use App\DTO\Tenant\AutXmlStreamData;
use App\Enums\CaptureChannel;
use App\Enums\TenantAutXmlEnrollmentStatus;
use App\Exceptions\TenantAutXmlApiException;
use App\Models\Establishment;
use App\Models\TenantAutXmlEnrollment;
use App\Models\TenantDistributionCursor;
use App\Models\TenantFiscalIdentity;
use App\Models\User;
use App\Services\Certificates\TenantFiscalIdentityService;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class TenantAutXmlEnrollmentService
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantFiscalIdentityService $identities,
    ) {}

    public function activeIdentity(): ?TenantFiscalIdentity
    {
        return $this->identities->activeForCurrentTenant();
    }

    public function primaryCursor(
        int $tenantId,
        bool $lockForUpdate = false,
    ): ?TenantDistributionCursor {
        $query = TenantDistributionCursor::query()
            ->where('tenant_id', $tenantId)
            ->where('channel', CaptureChannel::NfeAutXmlDistDfe)
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function streamGate(?TenantDistributionCursor $cursor): AutXmlStreamData
    {
        $quietHours = max(
            0.0,
            (float) config('sefaz.autxml.quiet_hours_after_empty', 1),
        );

        if ($cursor === null) {
            return new AutXmlStreamData(
                streamReady: false,
                streamReason: 'CURSOR_MISSING',
                quietHours: $quietHours,
                activatedAt: null,
                readyAt: null,
            );
        }

        if ($cursor->activated_at === null) {
            return new AutXmlStreamData(
                streamReady: false,
                streamReason: 'NOT_ACTIVATED',
                quietHours: $quietHours,
                activatedAt: null,
                readyAt: null,
            );
        }

        $readyAt = $cursor->activated_at->addHours($quietHours);
        $ready = $quietHours <= 0 || $readyAt->lte(now());

        return new AutXmlStreamData(
            streamReady: $ready,
            streamReason: $ready ? null : 'QUIET_PENDING',
            quietHours: $quietHours,
            activatedAt: $cursor->activated_at->toIso8601String(),
            readyAt: $readyAt->toIso8601String(),
        );
    }

    public function ensurePending(int $establishmentId): TenantAutXmlEnrollment
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;

        return DB::transaction(function () use ($establishmentId, $tenantId): TenantAutXmlEnrollment {
            $identity = $this->activeIdentity();
            if ($identity === null) {
                throw TenantAutXmlApiException::activeIdentityRequired();
            }

            $establishment = Establishment::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($establishmentId)
                ->lockForUpdate()
                ->first();
            if ($establishment === null) {
                throw TenantAutXmlApiException::establishmentNotFound();
            }

            if (! $establishment->is_active) {
                throw TenantAutXmlApiException::inactiveEstablishment();
            }

            $enrollment = TenantAutXmlEnrollment::query()
                ->where('tenant_id', $tenantId)
                ->where('tenant_fiscal_identity_id', $identity->id)
                ->where('establishment_id', $establishment->id)
                ->lockForUpdate()
                ->first();

            if ($enrollment === null) {
                return TenantAutXmlEnrollment::query()->create([
                    'tenant_id' => $tenantId,
                    'tenant_fiscal_identity_id' => $identity->id,
                    'establishment_id' => $establishment->id,
                    'status' => TenantAutXmlEnrollmentStatus::Pending,
                ]);
            }

            if ($enrollment->status === TenantAutXmlEnrollmentStatus::Inactive) {
                $enrollment->forceFill([
                    'status' => TenantAutXmlEnrollmentStatus::Pending,
                    'activated_at' => null,
                    'confirmed_by' => null,
                ])->save();
            }

            return $enrollment->fresh() ?? $enrollment;
        });
    }

    public function confirm(int $enrollmentId, User $actor): TenantAutXmlEnrollment
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;

        return DB::transaction(function () use (
            $actor,
            $enrollmentId,
            $tenantId,
        ): TenantAutXmlEnrollment {
            $enrollment = $this->scopedEnrollment(
                $tenantId,
                $enrollmentId,
                lockForUpdate: true,
            );

            if ($enrollment->status === TenantAutXmlEnrollmentStatus::Inactive) {
                throw TenantAutXmlApiException::inactiveEnrollment();
            }

            $stream = $this->streamGate(
                $this->primaryCursor($tenantId, lockForUpdate: true),
            );
            if (! $stream->streamReady) {
                throw TenantAutXmlApiException::streamNotReady($stream);
            }

            $enrollment->forceFill([
                'status' => TenantAutXmlEnrollmentStatus::Confirmed,
                'activated_at' => $enrollment->activated_at ?? now(),
                'confirmed_by' => $actor->id,
            ])->save();

            return $enrollment->fresh() ?? $enrollment;
        });
    }

    public function inactivate(int $enrollmentId): TenantAutXmlEnrollment
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;

        return DB::transaction(function () use (
            $enrollmentId,
            $tenantId,
        ): TenantAutXmlEnrollment {
            $enrollment = $this->scopedEnrollment(
                $tenantId,
                $enrollmentId,
                lockForUpdate: true,
            );
            $enrollment->forceFill([
                'status' => TenantAutXmlEnrollmentStatus::Inactive,
            ])->save();

            return $enrollment->fresh() ?? $enrollment;
        });
    }

    private function scopedEnrollment(
        int $tenantId,
        int $enrollmentId,
        bool $lockForUpdate = false,
    ): TenantAutXmlEnrollment {
        $query = TenantAutXmlEnrollment::query()
            ->where('tenant_id', $tenantId)
            ->whereKey($enrollmentId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first()
            ?? throw TenantAutXmlApiException::enrollmentNotFound();
    }
}

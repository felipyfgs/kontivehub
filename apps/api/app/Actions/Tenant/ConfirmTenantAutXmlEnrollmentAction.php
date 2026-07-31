<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\AutXmlEnrollmentData;
use App\Exceptions\TenantAutXmlApiException;
use App\Models\Establishment;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Sefaz\TenantAutXmlEnrollmentService;
use App\Support\CurrentTenant;

final readonly class ConfirmTenantAutXmlEnrollmentAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantAutXmlEnrollmentService $enrollments,
        private AuditLogger $audit,
    ) {}

    public function __invoke(int $enrollmentId, User $actor): AutXmlEnrollmentData
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;
        $enrollment = $this->enrollments->confirm($enrollmentId, $actor);
        $establishment = Establishment::query()
            ->where('tenant_id', $tenantId)
            ->with('client:id,legal_name,display_name')
            ->find($enrollment->establishment_id)
            ?? throw TenantAutXmlApiException::establishmentNotFound();

        $this->audit->record(
            'tenant_autxml.enrollment_confirm',
            'SUCCESS',
            $enrollment,
            [
                'establishment_id' => $enrollment->establishment_id,
                'first_seen_at' => $enrollment->first_seen_at?->toIso8601String(),
            ],
        );

        return new AutXmlEnrollmentData($establishment, $enrollment);
    }
}

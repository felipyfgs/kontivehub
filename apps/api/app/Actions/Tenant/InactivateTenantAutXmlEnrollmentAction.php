<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\TenantAutXmlEnrollmentData;
use App\Exceptions\TenantAutXmlApiException;
use App\Models\Establishment;
use App\Services\Audit\AuditLogger;
use App\Services\Sefaz\TenantAutXmlEnrollmentService;
use App\Support\CurrentTenant;

final readonly class InactivateTenantAutXmlEnrollmentAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantAutXmlEnrollmentService $enrollments,
        private AuditLogger $audit,
    ) {}

    public function __invoke(int $enrollmentId): TenantAutXmlEnrollmentData
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;
        $enrollment = $this->enrollments->inactivate($enrollmentId);
        $establishment = Establishment::query()
            ->where('tenant_id', $tenantId)
            ->with('client:id,legal_name,display_name')
            ->find($enrollment->establishment_id)
            ?? throw TenantAutXmlApiException::establishmentNotFound();

        $this->audit->record(
            'tenant_autxml.enrollment_inactivate',
            'SUCCESS',
            $enrollment,
            ['establishment_id' => $enrollment->establishment_id],
        );

        return new TenantAutXmlEnrollmentData($establishment, $enrollment);
    }
}

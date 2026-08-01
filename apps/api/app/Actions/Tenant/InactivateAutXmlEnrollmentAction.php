<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\AutXmlEnrollmentData;
use App\Exceptions\TenantAutXmlApiException;
use App\Models\Establishment;
use App\Services\Audit\AuditLogger;
use App\Services\Sefaz\TenantAutXmlEnrollmentService;
use App\Support\CurrentTenant;

final readonly class InactivateAutXmlEnrollmentAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantAutXmlEnrollmentService $enrollments,
        private AuditLogger $audit,
    ) {}

    public function __invoke(int $enrollmentId): AutXmlEnrollmentData
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

        return new AutXmlEnrollmentData($establishment, $enrollment);
    }
}

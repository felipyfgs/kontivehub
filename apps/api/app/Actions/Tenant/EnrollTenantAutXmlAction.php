<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\TenantAutXmlEnrollmentData;
use App\Exceptions\TenantAutXmlApiException;
use App\Models\Establishment;
use App\Services\Audit\AuditLogger;
use App\Services\Sefaz\TenantAutXmlEnrollmentService;
use App\Support\CurrentTenant;

final readonly class EnrollTenantAutXmlAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantAutXmlEnrollmentService $enrollments,
        private AuditLogger $audit,
    ) {}

    public function __invoke(int $establishmentId): TenantAutXmlEnrollmentData
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;
        $enrollment = $this->enrollments->ensurePending($establishmentId);
        $establishment = Establishment::query()
            ->where('tenant_id', $tenantId)
            ->with('client:id,legal_name,display_name')
            ->find($enrollment->establishment_id)
            ?? throw TenantAutXmlApiException::establishmentNotFound();

        $this->audit->record('tenant_autxml.enroll', 'SUCCESS', $enrollment, [
            'establishment_id' => $establishment->id,
            'status' => $enrollment->status->value,
        ]);

        return new TenantAutXmlEnrollmentData($establishment, $enrollment);
    }
}

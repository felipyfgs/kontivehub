<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\TenantAutXmlEnrollmentData;
use App\DTO\Tenant\TenantAutXmlOverviewData;
use App\Models\Establishment;
use App\Models\TenantAutXmlEnrollment;
use App\Services\Sefaz\TenantAutXmlEnrollmentService;
use App\Support\CurrentTenant;

final readonly class ShowTenantAutXmlOverviewAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantAutXmlEnrollmentService $enrollments,
    ) {}

    public function __invoke(int $perPage): TenantAutXmlOverviewData
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;
        $identity = $this->enrollments->activeIdentity();
        $cursor = $this->enrollments->primaryCursor($tenantId);
        $establishments = Establishment::query()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->with('client:id,legal_name,display_name')
            ->orderBy('cnpj')
            ->orderBy('id')
            ->paginate($perPage);

        $enrollmentsByEstablishment = collect();
        $establishmentIds = $establishments->getCollection()->pluck('id');
        if ($identity !== null && $establishmentIds->isNotEmpty()) {
            $enrollmentsByEstablishment = TenantAutXmlEnrollment::query()
                ->where('tenant_id', $tenantId)
                ->where('tenant_fiscal_identity_id', $identity->id)
                ->whereIn('establishment_id', $establishmentIds)
                ->get()
                ->keyBy('establishment_id');
        }

        $establishments->setCollection(
            $establishments->getCollection()
                ->map(fn (Establishment $establishment): TenantAutXmlEnrollmentData => new TenantAutXmlEnrollmentData(
                    establishment: $establishment,
                    enrollment: $enrollmentsByEstablishment->get($establishment->id),
                )),
        );

        return new TenantAutXmlOverviewData(
            identity: $identity,
            cursor: $cursor,
            stream: $this->enrollments->streamGate($cursor),
            enrollments: $establishments,
        );
    }
}

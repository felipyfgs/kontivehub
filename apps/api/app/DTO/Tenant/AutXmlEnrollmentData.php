<?php

namespace App\DTO\Tenant;

use App\Models\Establishment;
use App\Models\TenantAutXmlEnrollment;

final readonly class AutXmlEnrollmentData
{
    public function __construct(
        public Establishment $establishment,
        public ?TenantAutXmlEnrollment $enrollment,
    ) {}
}

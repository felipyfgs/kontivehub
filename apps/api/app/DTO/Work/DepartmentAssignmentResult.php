<?php

namespace App\DTO\Work;

final readonly class DepartmentAssignmentResult
{
    public function __construct(
        public int $membershipId,
        public int $workDepartmentId,
    ) {}
}

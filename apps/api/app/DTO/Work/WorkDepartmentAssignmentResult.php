<?php

namespace App\DTO\Work;

final readonly class WorkDepartmentAssignmentResult
{
    public function __construct(
        public int $membershipId,
        public int $workDepartmentId,
    ) {}
}

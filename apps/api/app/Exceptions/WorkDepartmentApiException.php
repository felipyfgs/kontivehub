<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class WorkDepartmentApiException extends ApiDomainException implements ShouldntReport
{
    public static function inactive(): self
    {
        return new self(
            'work_department_inactive',
            'Departamento inativo.',
            422,
        );
    }

    private function __construct(
        string $stableCode,
        string $safeMessage,
        int $httpStatus,
    ) {
        parent::__construct($stableCode, $safeMessage, $httpStatus);
    }
}

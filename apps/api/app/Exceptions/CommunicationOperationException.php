<?php

namespace App\Exceptions;

use App\Enums\Communication\CommunicationOperationFailure;
use Illuminate\Contracts\Debug\ShouldntReport;

final class CommunicationOperationException extends ApiDomainException implements ShouldntReport
{
    public function __construct(public readonly CommunicationOperationFailure $failure)
    {
        parent::__construct(
            stableCode: $failure->value,
            safeMessage: $failure->safeMessage(),
            httpStatus: $failure->httpStatus(),
        );
    }
}

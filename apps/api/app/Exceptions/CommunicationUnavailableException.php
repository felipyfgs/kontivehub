<?php

namespace App\Exceptions;

use App\Enums\Communication\AvailabilityFailure;
use Illuminate\Contracts\Debug\ShouldntReport;

final class CommunicationUnavailableException extends ApiDomainException implements ShouldntReport
{
    public function __construct(public readonly AvailabilityFailure $failure)
    {
        parent::__construct(
            stableCode: $failure->value,
            safeMessage: 'Canal de comunicação indisponível.',
            httpStatus: $failure->httpStatus(),
        );
    }
}

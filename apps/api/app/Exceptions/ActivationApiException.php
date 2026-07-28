<?php

namespace App\Exceptions;

use App\Services\Activation\ActivationException;
use Illuminate\Contracts\Debug\ShouldntReport;

final class ActivationApiException extends ApiDomainException implements ShouldntReport
{
    public static function fromDomain(ActivationException $error): self
    {
        return new self(
            stableCode: $error->errorCode,
            safeMessage: $error->getMessage(),
            httpStatus: $error->status,
        );
    }

    private function __construct(string $stableCode, string $safeMessage, int $httpStatus)
    {
        parent::__construct($stableCode, $safeMessage, $httpStatus);
    }
}

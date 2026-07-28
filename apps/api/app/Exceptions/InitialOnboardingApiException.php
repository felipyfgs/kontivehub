<?php

namespace App\Exceptions;

use App\Services\Platform\InitialOnboardingException;
use Illuminate\Contracts\Debug\ShouldntReport;

final class InitialOnboardingApiException extends ApiDomainException implements ShouldntReport
{
    public static function fromDomain(InitialOnboardingException $error): self
    {
        return new self(
            stableCode: $error->errorCode,
            safeMessage: $error->getMessage(),
            httpStatus: $error->status,
        );
    }

    private function __construct(
        string $stableCode,
        string $safeMessage,
        int $httpStatus,
    ) {
        parent::__construct(
            stableCode: $stableCode,
            safeMessage: $safeMessage,
            httpStatus: $httpStatus,
            responseHeaders: ['Cache-Control' => 'no-store, private'],
        );
    }
}

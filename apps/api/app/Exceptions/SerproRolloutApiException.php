<?php

namespace App\Exceptions;

use App\Services\Serpro\SerproRolloutException;
use App\Support\LogSanitizer;
use Illuminate\Contracts\Debug\ShouldntReport;

final class SerproRolloutApiException extends ApiDomainException implements ShouldntReport
{
    public static function fromDomain(
        SerproRolloutException $error,
        string $stableCode,
    ): self {
        return new self(
            $stableCode,
            LogSanitizer::scrubString($error->getMessage()),
            422,
        );
    }

    private function __construct(string $stableCode, string $safeMessage, int $httpStatus)
    {
        parent::__construct($stableCode, $safeMessage, $httpStatus);
    }
}

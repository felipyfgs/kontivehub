<?php

namespace App\Exceptions;

use App\Services\Serpro\SerproDteCanaryException;
use Illuminate\Contracts\Debug\ShouldntReport;

final class DteCanaryApiException extends ApiDomainException implements ShouldntReport
{
    public static function fromDomain(
        SerproDteCanaryException $error,
        string $stableCode,
        int $httpStatus = 422,
    ): self {
        return new self($stableCode, $error->getMessage(), $httpStatus);
    }

    public static function forbiddenField(string $field): self
    {
        return new self(
            'forbidden_field',
            "Campo {$field} não é aceito na execução do canário DTE.",
            422,
        );
    }

    public static function clientTenantIdRejected(string $safeMessage): self
    {
        return new self('forbidden_field', $safeMessage, 422);
    }

    private function __construct(string $stableCode, string $safeMessage, int $httpStatus)
    {
        parent::__construct($stableCode, $safeMessage, $httpStatus);
    }
}

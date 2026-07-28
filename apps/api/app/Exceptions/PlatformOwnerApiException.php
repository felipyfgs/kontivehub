<?php

namespace App\Exceptions;

use App\Services\Platform\PlatformOwnerException;
use Illuminate\Contracts\Debug\ShouldntReport;

final class PlatformOwnerApiException extends ApiDomainException implements ShouldntReport
{
    public static function noFields(): self
    {
        return new self(
            stableCode: 'platform_owner_invalid',
            safeMessage: 'Nenhum campo para atualizar.',
            httpStatus: 422,
        );
    }

    public static function fromDomain(PlatformOwnerException $error): self
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
        parent::__construct($stableCode, $safeMessage, $httpStatus);
    }
}

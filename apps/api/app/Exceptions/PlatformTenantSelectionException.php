<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class PlatformTenantSelectionException extends ApiDomainException implements ShouldntReport
{
    public static function forbidden(): self
    {
        return new self(
            stableCode: 'http_error',
            safeMessage: 'Ação restrita a administradores da plataforma.',
            httpStatus: 403,
        );
    }

    public static function privilegedContextDisabled(): self
    {
        return new self(
            stableCode: 'privileged_context_disabled',
            safeMessage: 'Contexto privilegiado da plataforma indisponível.',
            httpStatus: 403,
        );
    }

    public static function tenantNotFound(): self
    {
        return new self(
            stableCode: 'http_error',
            safeMessage: 'Escritório não encontrado.',
            httpStatus: 404,
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

<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class TenantSerproAuthorizationApiException extends ApiDomainException implements ShouldntReport
{
    public static function operationFailed(string $message): self
    {
        return new self(
            'tenant_serpro_authorization_failed',
            $message,
            422,
        );
    }

    public static function termProcessingFailed(): self
    {
        return new self(
            'tenant_serpro_term_processing_failed',
            'Falha ao processar Termo.',
            422,
        );
    }

    public static function termDraftNotFound(string $message): self
    {
        return new self(
            'tenant_serpro_term_draft_not_found',
            $message,
            404,
        );
    }

    public static function manualProxyPowerRejected(string $message): self
    {
        return new self(
            'tenant_proxy_power_manual_override_rejected',
            $message,
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

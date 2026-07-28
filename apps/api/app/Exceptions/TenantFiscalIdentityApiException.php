<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class TenantFiscalIdentityApiException extends ApiDomainException implements ShouldntReport
{
    public static function mutationFailed(): self
    {
        return new self(
            'tenant_fiscal_identity_invalid',
            'Não foi possível validar a identidade fiscal informada.',
        );
    }

    private function __construct(string $stableCode, string $safeMessage)
    {
        parent::__construct($stableCode, $safeMessage, 422);
    }
}

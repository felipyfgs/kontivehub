<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class FiscalModuleRestrictionException extends ApiDomainException implements ShouldntReport
{
    public static function passwordConfirmationRequired(): self
    {
        return new self;
    }

    private function __construct()
    {
        parent::__construct(
            stableCode: 'password_confirmation_required',
            safeMessage: 'Liberar um módulo exige reconfirmação de senha recente.',
            httpStatus: 403,
        );
    }
}

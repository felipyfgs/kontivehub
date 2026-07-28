<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class InvalidPasswordException extends ApiDomainException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct(
            stableCode: 'PASSWORD_INVALID',
            safeMessage: 'Senha inválida.',
            httpStatus: 422,
        );
    }
}

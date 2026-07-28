<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class RecentPasswordConfirmationRequiredException extends ApiDomainException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct(
            stableCode: 'password_confirmation_required',
            safeMessage: 'Operação exige reconfirmação de senha recente.',
            httpStatus: 403,
        );
    }
}

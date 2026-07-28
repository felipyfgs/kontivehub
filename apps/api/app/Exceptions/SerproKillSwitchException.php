<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class SerproKillSwitchException extends ApiDomainException implements ShouldntReport
{
    public static function passwordConfirmationRequired(): self
    {
        return new self(
            stableCode: 'password_confirmation_required',
            safeMessage: 'Retirada de kill switch exige reconfirmação de senha recente.',
            httpStatus: 403,
        );
    }

    public static function ownerConfirmationFailed(): self
    {
        return new self(
            stableCode: 'owner_confirmation_failed',
            safeMessage: 'Confirmação do proprietário inválida ou não executada.',
            httpStatus: 422,
        );
    }

    private function __construct(string $stableCode, string $safeMessage, int $httpStatus)
    {
        parent::__construct($stableCode, $safeMessage, $httpStatus);
    }
}

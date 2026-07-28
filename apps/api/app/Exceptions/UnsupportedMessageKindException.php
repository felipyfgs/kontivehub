<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class UnsupportedMessageKindException extends ApiDomainException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct(
            stableCode: 'MESSAGE_KIND_UNSUPPORTED',
            safeMessage: 'Tipo de mensagem não permitido.',
            httpStatus: 422,
        );
    }
}

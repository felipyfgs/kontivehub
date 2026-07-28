<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class AssistantUnavailableException extends ApiDomainException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct(
            stableCode: 'ASSISTANT_DISABLED',
            safeMessage: 'Assistente indisponível.',
            httpStatus: 503,
        );
    }
}

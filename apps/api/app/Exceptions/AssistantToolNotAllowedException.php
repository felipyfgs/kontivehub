<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class AssistantToolNotAllowedException extends ApiDomainException implements ShouldntReport
{
    public function __construct()
    {
        parent::__construct(
            stableCode: 'ASSISTANT_TOOL_UNKNOWN',
            safeMessage: 'Tool do assistente não permitida.',
            httpStatus: 422,
        );
    }
}

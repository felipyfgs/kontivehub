<?php

namespace App\Exceptions;

use App\Enums\Communication\FlowFailure;
use Illuminate\Contracts\Debug\ShouldntReport;

final class CommunicationFlowException extends ApiDomainException implements ShouldntReport
{
    public function __construct(public readonly FlowFailure $failure)
    {
        parent::__construct(
            stableCode: $failure->value,
            safeMessage: 'Operação de run de fluxo rejeitada.',
            httpStatus: $failure->httpStatus(),
        );
    }
}

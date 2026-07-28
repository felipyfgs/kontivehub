<?php

namespace App\Exceptions;

use App\Enums\Communication\CommunicationFlowFailure;
use Illuminate\Contracts\Debug\ShouldntReport;

final class CommunicationFlowException extends ApiDomainException implements ShouldntReport
{
    public function __construct(public readonly CommunicationFlowFailure $failure)
    {
        parent::__construct(
            stableCode: $failure->value,
            safeMessage: 'Operação de run de fluxo rejeitada.',
            httpStatus: $failure->httpStatus(),
        );
    }
}

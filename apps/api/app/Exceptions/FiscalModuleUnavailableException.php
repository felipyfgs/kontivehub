<?php

namespace App\Exceptions;

use App\DTO\Fiscal\FiscalModuleAvailabilityDecision;
use RuntimeException;

final class FiscalModuleUnavailableException extends RuntimeException
{
    public function __construct(public readonly FiscalModuleAvailabilityDecision $decision)
    {
        parent::__construct($decision->reason ?? 'Módulo fiscal indisponível.');
    }
}

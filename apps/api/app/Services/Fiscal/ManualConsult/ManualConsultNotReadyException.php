<?php

namespace App\Services\Fiscal\ManualConsult;

use App\Enums\ManualConsultEligibility;
use RuntimeException;

/**
 * Ação de consulta manual não está ready (módulo/capability/token/poder/adapter).
 */
final class ManualConsultNotReadyException extends RuntimeException
{
    public function __construct(public readonly ManualConsultEligibility $eligibility)
    {
        parent::__construct($eligibility->label());
    }
}

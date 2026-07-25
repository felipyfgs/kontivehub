<?php

namespace App\Contracts;

use App\Enums\SignatureVerificationResult;

/**
 * Valida digest e XMLDSig de CT-e/evento sem conhecer transporte ou persistência.
 */
interface CteXmlSignatureValidator
{
    public function validate(
        string $xmlBytes,
        bool $allowOfficialRedaction = false,
    ): SignatureVerificationResult;
}

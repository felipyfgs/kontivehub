<?php

namespace App\Enums\Communication;

enum SignatureVerificationResult: string
{
    case Valid = 'VALID';
    case MissingHeaders = 'MISSING_HEADERS';
    case InvalidTimestamp = 'INVALID_TIMESTAMP';
    case StaleTimestamp = 'STALE_TIMESTAMP';
    case InvalidNonce = 'INVALID_NONCE';
    case Replay = 'REPLAY';
    case UnknownKey = 'UNKNOWN_KEY';
    case InvalidSignature = 'INVALID_SIGNATURE';

    public function accepted(): bool
    {
        return $this === self::Valid;
    }
}

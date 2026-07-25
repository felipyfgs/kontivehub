<?php

namespace App\Enums;

/**
 * Resultado da verificação criptográfica (XMLDSig) do artefato.
 * NOT_VERIFIABLE_OFFICIAL_REDACTION: redação oficial autXML impede verificação byte-a-byte.
 */
enum SignatureVerificationResult: string
{
    case Valid = 'VALID';
    case Invalid = 'INVALID';
    case NotVerifiableOfficialRedaction = 'NOT_VERIFIABLE_OFFICIAL_REDACTION';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Assinatura válida',
            self::Invalid => 'Assinatura inválida',
            self::NotVerifiableOfficialRedaction => 'Não verificável (redação oficial)',
        };
    }
}

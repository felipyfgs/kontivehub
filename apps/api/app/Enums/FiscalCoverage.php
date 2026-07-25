<?php

namespace App\Enums;

/**
 * Cobertura oficial da informação fiscal.
 * Ausência de fonte NÃO é FULL — use UNSUPPORTED/UNKNOWN/PARTIAL.
 */
enum FiscalCoverage: string
{
    case Full = 'FULL';
    case Partial = 'PARTIAL';
    case Unsupported = 'UNSUPPORTED';
    case Unknown = 'UNKNOWN';
    case NotApplicable = 'NOT_APPLICABLE';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Cobertura completa',
            self::Partial => 'Cobertura parcial',
            self::Unsupported => 'Não suportado por fonte oficial',
            self::Unknown => 'Cobertura desconhecida',
            self::NotApplicable => 'Não aplicável',
        };
    }

    /** Cobertura que permite afirmar regularidade plena. */
    public function allowsUpToDateClaim(): bool
    {
        return $this === self::Full;
    }
}

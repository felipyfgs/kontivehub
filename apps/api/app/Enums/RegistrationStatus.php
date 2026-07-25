<?php

namespace App\Enums;

enum RegistrationStatus: string
{
    case Active = 'ACTIVE';
    case Void = 'VOID';
    case Suspended = 'SUSPENDED';
    case Unfit = 'UNFIT';
    case Closed = 'CLOSED';
    case Unknown = 'UNKNOWN';

    public function isActive(): bool
    {
        return $this === self::Active;
    }

    /**
     * Normaliza rótulos da fonte pública (PT/EN) para o enum canônico.
     */
    public static function fromExternal(?string $raw): self
    {
        if ($raw === null) {
            return self::Unknown;
        }

        $normalized = mb_strtoupper(trim($raw));
        $normalized = str_replace(['Á', 'À', 'Â', 'Ã'], 'A', $normalized);
        $normalized = str_replace(['É', 'Ê'], 'E', $normalized);
        $normalized = str_replace(['Í'], 'I', $normalized);
        $normalized = str_replace(['Ó', 'Ô', 'Õ'], 'O', $normalized);
        $normalized = str_replace(['Ú', 'Ü'], 'U', $normalized);
        $normalized = str_replace(['Ç'], 'C', $normalized);

        return match (true) {
            in_array($normalized, ['ATIVA', 'ATIVO', 'ACTIVE', '01', '1'], true) => self::Active,
            in_array($normalized, ['NULA', 'NULO', 'VOID', '02', '2'], true) => self::Void,
            in_array($normalized, ['SUSPENSA', 'SUSPENSO', 'SUSPENDED', '03', '3'], true) => self::Suspended,
            in_array($normalized, ['INAPTA', 'INAPTO', 'UNFIT', '04', '4'], true) => self::Unfit,
            in_array($normalized, ['BAIXADA', 'BAIXADO', 'CLOSED', 'BAIXA', '05', '5'], true) => self::Closed,
            default => self::Unknown,
        };
    }
}

<?php

namespace App\Enums;

/**
 * Categorias oficiais DCTFWeb usadas no monitoramento.
 * GERAL_MENSAL (40) é a categoria principal da grade mensal.
 */
enum DctfwebCategory: string
{
    case GeralMensal = 'GERAL_MENSAL';

    /** Código numérico oficial enviado em CONSRECIBO32.categoria. */
    public function officialCode(): string
    {
        return match ($this) {
            self::GeralMensal => '40',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GeralMensal => 'Geral mensal',
        };
    }

    public static function fromOfficialCode(string|int $code): ?self
    {
        $normalized = trim((string) $code);
        if ($normalized === '40' || strtoupper($normalized) === self::GeralMensal->value) {
            return self::GeralMensal;
        }

        return null;
    }

    public static function default(): self
    {
        return self::GeralMensal;
    }
}

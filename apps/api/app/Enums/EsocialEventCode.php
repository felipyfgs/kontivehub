<?php

namespace App\Enums;

/**
 * Eventos eSocial oficiais admitidos no módulo FGTS parcial.
 * MUST NOT incluir eventos de portal FGTS Digital ou fontes humanas.
 */
enum EsocialEventCode: string
{
    /** Base de cálculo do FGTS (totalizador). */
    case S5003 = 'S-5003';

    /** Informações do FGTS consolidadas (totalizador). */
    case S5013 = 'S-5013';

    /** Fechamento dos eventos periódicos. */
    case S1299 = 'S-1299';

    public function label(): string
    {
        return match ($this) {
            self::S5003 => 'Totalizador base FGTS (S-5003)',
            self::S5013 => 'Informações consolidadas FGTS (S-5013)',
            self::S1299 => 'Fechamento de eventos periódicos (S-1299)',
        };
    }

    public function isTotalizer(): bool
    {
        return match ($this) {
            self::S5003, self::S5013 => true,
            self::S1299 => false,
        };
    }

    public function isClosure(): bool
    {
        return $this === self::S1299;
    }

    /**
     * @return list<self>
     */
    public static function supported(): array
    {
        return [self::S5003, self::S5013, self::S1299];
    }

    public static function tryFromOfficial(string $code): ?self
    {
        $normalized = strtoupper(trim(str_replace('_', '-', $code)));

        return self::tryFrom($normalized);
    }
}

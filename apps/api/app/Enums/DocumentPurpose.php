<?php

namespace App\Enums;

/**
 * Finalidade do documento no catálogo.
 * TECHNICAL: sonda/autorização inesperada ou artefato técnico — visível, não ocultável.
 */
enum DocumentPurpose: string
{
    case Commercial = 'COMMERCIAL';
    case Technical = 'TECHNICAL';

    public function label(): string
    {
        return match ($this) {
            self::Commercial => 'Comercial',
            self::Technical => 'Técnico',
        };
    }
}

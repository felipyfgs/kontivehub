<?php

namespace App\Enums;

/**
 * Rotas funcionais oficiais do Integra Contador (path relativo à base).
 */
enum SerproFunctionalRoute: string
{
    case Apoiar = 'Apoiar';
    case Consultar = 'Consultar';
    case Declarar = 'Declarar';
    case Emitir = 'Emitir';
    case Monitorar = 'Monitorar';

    public function path(): string
    {
        return '/'.$this->value;
    }

    public function isNonBillableByRoute(): bool
    {
        return $this === self::Apoiar || $this === self::Monitorar;
    }
}

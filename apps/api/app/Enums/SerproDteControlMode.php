<?php

namespace App\Enums;

/**
 * Controle operacional de DTE após go-live controlado.
 *
 * DISABLED — nenhuma reserva/dispatch
 * CANARY   — exatamente uma tentativa unitária no Office/cliente piloto
 * LIMITED  — mesmo Office, teto quantitativo (padrão 10 no ciclo)
 */
enum SerproDteControlMode: string
{
    case Disabled = 'DISABLED';
    case Canary = 'CANARY';
    case Limited = 'LIMITED';

    public function allowsNewReservation(): bool
    {
        return $this === self::Canary || $this === self::Limited;
    }
}

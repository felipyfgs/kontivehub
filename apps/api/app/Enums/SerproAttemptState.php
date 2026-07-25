<?php

namespace App\Enums;

/**
 * Máquina de estados de tentativa remota Integra Contador.
 *
 * reserved → dispatched → acknowledged | uncertain → reconciled
 */
enum SerproAttemptState: string
{
    case Reserved = 'reserved';
    case Dispatched = 'dispatched';
    case Acknowledged = 'acknowledged';
    case Uncertain = 'uncertain';
    case Reconciled = 'reconciled';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Acknowledged, self::Uncertain, self::Reconciled => true,
            self::Reserved, self::Dispatched => false,
        };
    }

    public function isInFlight(): bool
    {
        return $this === self::Dispatched;
    }

    public function mayDispatch(): bool
    {
        return $this === self::Reserved;
    }
}

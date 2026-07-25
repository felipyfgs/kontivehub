<?php

namespace App\Enums;

/** Ciclo de vida de tentativa mutante (transmissão / encerramento). */
enum DctfwebMutationStatus: string
{
    case Pending = 'PENDING';
    case Sent = 'SENT';
    case Uncertain = 'UNCERTAIN';
    case Confirmed = 'CONFIRMED';
    case Failed = 'FAILED';
    case Blocked = 'BLOCKED';

    public function blocksRetry(): bool
    {
        return $this === self::Uncertain || $this === self::Sent;
    }
}

<?php

namespace App\Enums;

/**
 * Resultado da execução HTTP/lógica atribuído ao ledger.
 * Falhas podem ser faturáveis conforme regra contratual.
 */
enum SerproUsageResult: string
{
    case Success = 'SUCCESS';
    case HttpError = 'HTTP_ERROR';
    case Timeout = 'TIMEOUT';
    case ClientError = 'CLIENT_ERROR';
    case TransportError = 'TRANSPORT_ERROR';
    case Unknown = 'UNKNOWN';
    case BlockedByBudget = 'BLOCKED_BY_BUDGET';
    case Released = 'RELEASED';

    /**
     * Por padrão, tentativas que chegaram (ou tentaram chegar) ao SERPRO
     * são possivelmente faturáveis — inclusive falhas HTTP/timeout.
     */
    public function possiblyBillableByDefault(): bool
    {
        return match ($this) {
            self::Success, self::HttpError, self::Timeout, self::TransportError, self::Unknown => true,
            self::ClientError, self::BlockedByBudget, self::Released => false,
        };
    }
}

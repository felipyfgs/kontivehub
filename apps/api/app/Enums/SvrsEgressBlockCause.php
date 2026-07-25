<?php

namespace App\Enums;

/**
 * Causas tipadas de breaker/coorte do portal SVRS.
 */
enum SvrsEgressBlockCause: string
{
    case MultipleQueries = 'SVRS_EGRESS_BLOCKED_MULTIPLE_QUERIES';
    case RateHttp = 'SVRS_EGRESS_BLOCKED_HTTP_RATE';
    case ContractChanged = 'SVRS_EGRESS_CONTRACT_CHANGED';
    case Admin = 'SVRS_EGRESS_ADMIN';
    case CoordinatorUnavailable = 'SVRS_EGRESS_COORDINATOR_UNAVAILABLE';
    case RecurrentTransient = 'SVRS_EGRESS_RECURRENT_TRANSIENT';

    public function label(): string
    {
        return match ($this) {
            self::MultipleQueries => 'IP bloqueado por múltiplas consultas',
            self::RateHttp => 'HTTP 403/429 do portal',
            self::ContractChanged => 'Contrato de resposta alterado',
            self::Admin => 'Breaker administrativo',
            self::CoordinatorUnavailable => 'Coordenador de egress indisponível',
            self::RecurrentTransient => 'Falhas transitórias recorrentes',
        };
    }
}

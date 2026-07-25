<?php

namespace App\Enums;

/**
 * Motivos tipados de falha/bloqueio da recuperação SVRS (mensagens sanitizadas).
 */
enum SvrsNfceFailureReason: string
{
    case A1Unavailable = 'A1_UNAVAILABLE';
    case A1NotRelated = 'A1_NOT_RELATED';
    case HttpTransient = 'HTTP_TRANSIENT';
    case AuthForbidden = 'AUTH_FORBIDDEN';
    case RemoteNotFound = 'REMOTE_NOT_FOUND';
    case ResponseContractChanged = 'RESPONSE_CONTRACT_CHANGED';
    case InvalidXml = 'INVALID_XML';
    case IdentityMismatch = 'IDENTITY_MISMATCH';
    case InvalidSignature = 'INVALID_SIGNATURE';
    case DivergentBytes = 'DIVERGENT_BYTES';
    case RateLimited = 'RATE_LIMITED';
    case EgressBlockedMultipleQueries = 'SVRS_EGRESS_BLOCKED_MULTIPLE_QUERIES';
    case KillSwitch = 'KILL_SWITCH';
    case ChannelDisabled = 'CHANNEL_DISABLED';
    case BreakerOpen = 'BREAKER_OPEN';
    case NotEligible = 'NOT_ELIGIBLE';
    case MaxAttempts = 'MAX_ATTEMPTS';
    case CapturedByOther = 'CAPTURED_BY_OTHER';

    public function isRecoverable(): bool
    {
        return in_array($this, [
            self::HttpTransient,
            self::RateLimited,
            self::RemoteNotFound,
            self::A1Unavailable,
            // Operacionais: não terminalizar recovery — volta quando o canal reabre
            self::KillSwitch,
            self::ChannelDisabled,
            self::BreakerOpen,
            self::EgressBlockedMultipleQueries, // reabre após cooldown/canário
            self::AuthForbidden, // threshold no breaker; retry com backoff
        ], true);
    }

    /**
     * Trip global imediato (contrato sistêmico). AuthForbidden usa threshold.
     */
    public function opensGlobalBreaker(): bool
    {
        return in_array($this, [
            self::ResponseContractChanged,
            self::EgressBlockedMultipleQueries,
        ], true);
    }

    /**
     * Trip por raiz imediato (identidade/assinatura/A1).
     */
    public function opensRootBreaker(): bool
    {
        return in_array($this, [
            self::A1NotRelated,
            self::IdentityMismatch,
            self::InvalidSignature,
        ], true);
    }

    public function countsTowardGlobalThreshold(): bool
    {
        return $this === self::AuthForbidden;
    }

    public function label(): string
    {
        return match ($this) {
            self::A1Unavailable => 'Credencial A1 indisponível',
            self::A1NotRelated => 'A1 não relacionado à nota',
            self::HttpTransient => 'Falha HTTP transitória',
            self::AuthForbidden => 'Autenticação negada',
            self::RemoteNotFound => 'Documento não disponível na SVRS',
            self::ResponseContractChanged => 'Contrato do wrapper alterado',
            self::InvalidXml => 'XML inválido ou malformado',
            self::IdentityMismatch => 'Identidade/chave divergente',
            self::InvalidSignature => 'Assinatura ou digest inválidos',
            self::DivergentBytes => 'Bytes divergentes do canônico',
            self::RateLimited => 'Rate limit',
            self::EgressBlockedMultipleQueries => 'Portal bloqueou por múltiplas consultas',
            self::KillSwitch => 'Kill switch ativo',
            self::ChannelDisabled => 'Canal desabilitado',
            self::BreakerOpen => 'Circuit breaker aberto',
            self::NotEligible => 'Não elegível',
            self::MaxAttempts => 'Tentativas esgotadas',
            self::CapturedByOther => 'Capturado por outra fonte',
        };
    }
}

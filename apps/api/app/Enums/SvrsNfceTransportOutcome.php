<?php

namespace App\Enums;

/**
 * Resultado tipado do transporte HTTP+mTLS (sem corpo remoto bruto).
 */
enum SvrsNfceTransportOutcome: string
{
    case FormOk = 'FORM_OK';
    case Captured = 'CAPTURED';
    case RemoteNotFound = 'REMOTE_NOT_FOUND';
    case AuthForbidden = 'AUTH_FORBIDDEN';
    case RateLimited = 'RATE_LIMITED';
    /** Bloqueio textual "múltiplas consultas" mesmo com HTTP 200 */
    case EgressBlockedMultipleQueries = 'SVRS_EGRESS_BLOCKED_MULTIPLE_QUERIES';
    case HttpTransient = 'HTTP_TRANSIENT';
    case ResponseContractChanged = 'RESPONSE_CONTRACT_CHANGED';
    case TlsOrHostRejected = 'TLS_OR_HOST_REJECTED';
    case RedirectRejected = 'REDIRECT_REJECTED';
    case NetworkError = 'NETWORK_ERROR';
    case PayloadTooLarge = 'PAYLOAD_TOO_LARGE';
    case ChannelDisabled = 'CHANNEL_DISABLED';
    case KillSwitch = 'KILL_SWITCH';
    case BreakerOpen = 'BREAKER_OPEN';

    public function toFailureReason(): ?SvrsNfceFailureReason
    {
        return match ($this) {
            self::FormOk, self::Captured => null,
            self::RemoteNotFound => SvrsNfceFailureReason::RemoteNotFound,
            self::AuthForbidden => SvrsNfceFailureReason::AuthForbidden,
            self::RateLimited => SvrsNfceFailureReason::RateLimited,
            self::EgressBlockedMultipleQueries => SvrsNfceFailureReason::EgressBlockedMultipleQueries,
            self::HttpTransient, self::NetworkError => SvrsNfceFailureReason::HttpTransient,
            self::ResponseContractChanged => SvrsNfceFailureReason::ResponseContractChanged,
            self::TlsOrHostRejected, self::RedirectRejected => SvrsNfceFailureReason::HttpTransient,
            self::PayloadTooLarge => SvrsNfceFailureReason::InvalidXml,
            self::ChannelDisabled => SvrsNfceFailureReason::ChannelDisabled,
            self::KillSwitch => SvrsNfceFailureReason::KillSwitch,
            self::BreakerOpen => SvrsNfceFailureReason::BreakerOpen,
        };
    }
}

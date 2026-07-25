<?php

namespace App\Enums;

/**
 * Escada hierárquica de prontidão SERPRO (fail-closed).
 * Gate pai inválido retira todos os descendentes.
 */
enum SerproReadinessGate: string
{
    case Configured = 'CONFIGURED';
    case CredentialsRotated = 'CREDENTIALS_ROTATED';
    case TlsOk = 'TLS_OK';
    case OauthOk = 'OAUTH_OK';
    case TermLocalValid = 'TERM_LOCAL_VALID';
    case TermSerproAccepted = 'TERM_SERPRO_ACCEPTED';
    case PowersVerified = 'POWERS_VERIFIED';
    case FreeSmokeOk = 'FREE_SMOKE_OK';
    case CanaryReady = 'CANARY_READY';
    case ProductionReady = 'PRODUCTION_READY';

    /** Gates de contrato/ambiente global (sem Office). */
    public function isGlobalGate(): bool
    {
        return match ($this) {
            self::Configured,
            self::CredentialsRotated,
            self::TlsOk,
            self::OauthOk => true,
            default => false,
        };
    }

    /**
     * Ordem canônica (índice crescente = mais avançado).
     *
     * @return list<self>
     */
    public static function ladder(): array
    {
        return [
            self::Configured,
            self::CredentialsRotated,
            self::TlsOk,
            self::OauthOk,
            self::TermLocalValid,
            self::TermSerproAccepted,
            self::PowersVerified,
            self::FreeSmokeOk,
            self::CanaryReady,
            self::ProductionReady,
        ];
    }

    public function rank(): int
    {
        $ladder = self::ladder();
        $idx = array_search($this, $ladder, true);

        return $idx === false ? -1 : $idx;
    }
}

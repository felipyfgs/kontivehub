<?php

namespace App\Services\Serpro;

/**
 * Política de replay do attempt store: falhas de pré-condição local
 * recuperáveis não gravam sticky; respostas definitivas da operação sim.
 */
final class SerproAttemptReplayPolicy
{
    /**
     * Códigos de gate/pré-condição local que MUST NOT gerar replay sticky.
     *
     * @var list<string>
     */
    private const NON_STICKY_ERROR_CODES = [
        'PROCURADOR_TOKEN_MISSING',
        'PROCURADOR_TOKEN_EXPIRED',
        'PROCURADOR_TOKEN_EMPTY',
        'PROCURADOR_TOKEN_VAULT_UNREADABLE',
        'AUTHOR_IDENTITY_MISMATCH',
        'RATE_LIMIT_LOCAL',
        'RATE_LIMIT_NOT_CONFIGURED',
        'AUTHORIZATION_MISSING',
        'AUTHORIZATION_ACTION_REQUIRED',
        'AUTHOR_IDENTITY_MISSING',
        'CONTRACT_UNAVAILABLE',
        'CONTRACT_UNHEALTHY',
        'CONTRACTOR_MISMATCH',
        'TRIAL_CREDENTIALS_MISSING',
        'CAPABILITY_DISABLED',
        'KILL_SWITCH',
        'CIRCUIT_OPEN',
        'SUBSCRIPTION_BLOCKED',
        'BUDGET_EXCEEDED',
        'EGRESS_BLOCKED',
        'MODULE_UNAVAILABLE',
        'MODULE_UNKNOWN',
        'DRIVER_INVALID',
        'TECHNICAL_PARAM_REJECTED',
        'CONTRIBUTOR_IDENTITY_MISSING',
        'CONTRIBUTOR_CROSS_TENANT',
        'PROXY_POWER_MISSING',
        'PROCURACAO_SYNC_FAILED',
        'PROCURACAO_SYNC_BUSY',
        'CAPABILITY_NOT_IMPLEMENTED',
    ];

    /**
     * @return list<string>
     */
    public static function tokenRelatedNonStickyCodes(): array
    {
        return [
            'PROCURADOR_TOKEN_MISSING',
            'PROCURADOR_TOKEN_EXPIRED',
            'PROCURADOR_TOKEN_EMPTY',
            'PROCURADOR_TOKEN_VAULT_UNREADABLE',
            'AUTHOR_IDENTITY_MISMATCH',
            'AUTHORIZATION_MISSING',
            'AUTHOR_IDENTITY_MISSING',
        ];
    }

    public static function isStickyReplay(?string $errorCode, bool $success): bool
    {
        if ($success) {
            return true;
        }

        $code = strtoupper(trim((string) $errorCode));
        if ($code === '') {
            // Sem código: tratar como definitivo (fail-closed no replay).
            return true;
        }

        return ! in_array($code, self::NON_STICKY_ERROR_CODES, true);
    }

    public static function isNonStickyError(?string $errorCode): bool
    {
        return ! self::isStickyReplay($errorCode, success: false);
    }
}

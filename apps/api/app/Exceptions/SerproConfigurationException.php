<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class SerproConfigurationException extends ApiDomainException implements ShouldntReport
{
    public static function credentialRegistrationRejected(): self
    {
        return new self(
            'serpro_credential_registration_failed',
            'Não foi possível cadastrar a versão de credencial.',
            422,
        );
    }

    public static function credentialRegistrationFailed(): self
    {
        return new self(
            'serpro_credential_registration_failed',
            'Falha ao cadastrar versão de credencial.',
            500,
        );
    }

    public static function credentialVerificationRejected(): self
    {
        return new self(
            'serpro_credential_verification_failed',
            'Não foi possível verificar a versão de credencial.',
            422,
        );
    }

    public static function connectionTestRejected(): self
    {
        return new self(
            'serpro_credential_connection_test_failed',
            'Não foi possível concluir o teste de conexão.',
            422,
        );
    }

    public static function credentialActivationRejected(): self
    {
        return new self(
            'serpro_credential_activation_failed',
            'Não foi possível ativar a versão de credencial.',
            422,
        );
    }

    public static function unknownExternalGate(): self
    {
        return new self(
            'serpro_external_gate_not_found',
            'Gate desconhecido.',
            404,
        );
    }

    public static function externalGateRejected(): self
    {
        return new self(
            'serpro_external_gate_update_failed',
            'Não foi possível atualizar o gate externo.',
            422,
        );
    }

    public static function usageLimitsRejected(): self
    {
        return new self(
            'serpro_usage_limits_update_failed',
            'Não foi possível atualizar os limites de consumo.',
            422,
        );
    }

    private function __construct(string $stableCode, string $safeMessage, int $httpStatus)
    {
        parent::__construct($stableCode, $safeMessage, $httpStatus);
    }
}

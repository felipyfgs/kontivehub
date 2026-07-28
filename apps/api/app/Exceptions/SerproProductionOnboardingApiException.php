<?php

namespace App\Exceptions;

use App\Support\CurrentTenant;
use App\Support\LogSanitizer;
use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class SerproProductionOnboardingApiException extends ApiDomainException implements ShouldntReport
{
    public static function tenantRequired(): self
    {
        return new self(
            CurrentTenant::CONTEXT_STATUS_REQUIRED,
            'Selecione um escritório ativo para ativar SERPRO em produção.',
            409,
        );
    }

    public static function permissionDenied(): self
    {
        return new self(
            'tenant_permission_denied',
            'Você não possui permissão para gerenciar credenciais deste tenant.',
            403,
        );
    }

    public static function featureDisabled(): self
    {
        return new self(
            'feature_disabled',
            'Ativação simplificada SERPRO está desabilitada para este tenant.',
            403,
        );
    }

    public static function activationFailed(RuntimeException $error): self
    {
        return new self(
            'serpro_production_onboarding_failed',
            LogSanitizer::scrubString($error->getMessage()),
            422,
        );
    }

    public static function unexpectedFailure(): self
    {
        return new self(
            'serpro_production_onboarding_failed',
            'Falha ao ativar SERPRO em produção.',
            500,
        );
    }

    private function __construct(string $stableCode, string $safeMessage, int $httpStatus)
    {
        parent::__construct($stableCode, $safeMessage, $httpStatus);
    }
}

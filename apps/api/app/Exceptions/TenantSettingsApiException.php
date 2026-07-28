<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class TenantSettingsApiException extends ApiDomainException implements ShouldntReport
{
    public static function profileUpdateFailed(string $message): self
    {
        return new self('tenant_profile_update_failed', $message);
    }

    public static function consentGrantFailed(string $message): self
    {
        return new self('tenant_consent_grant_failed', $message);
    }

    public static function consentNotFound(): self
    {
        return new self(
            'tenant_consent_not_found',
            'Não há consentimento ativo para revogar.',
        );
    }

    public static function certificateMutationFailed(
        string $message,
        bool $previousPreserved = false,
    ): self {
        return new self(
            'tenant_certificate_mutation_failed',
            $message,
            $previousPreserved ? ['previous_preserved' => true] : [],
        );
    }

    public static function certificateNotFound(): self
    {
        return new self(
            'tenant_certificate_not_found',
            'Não há certificado ativo para remover.',
        );
    }

    public static function integrationCertificateRequired(): self
    {
        return new self(
            'tenant_integration_certificate_required',
            'Envie o certificado do escritório antes de atualizar a integração.',
        );
    }

    /** @param array<string, mixed> $responseData */
    public static function integrationRefreshFailed(
        string $message,
        array $responseData = [],
    ): self {
        return new self(
            'tenant_integration_refresh_failed',
            $message,
            $responseData,
        );
    }

    /** @param array<string, mixed> $responseData */
    private function __construct(
        string $stableCode,
        string $safeMessage,
        array $responseData = [],
        int $httpStatus = 422,
    ) {
        parent::__construct(
            $stableCode,
            $safeMessage,
            $httpStatus,
            $responseData,
        );
    }
}

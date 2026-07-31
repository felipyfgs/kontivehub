<?php

namespace App\Exceptions;

use App\DTO\Tenant\AutXmlStreamData;
use Illuminate\Contracts\Debug\ShouldntReport;

final class TenantAutXmlApiException extends ApiDomainException implements ShouldntReport
{
    public static function activeIdentityRequired(): self
    {
        return new self(
            'tenant_autxml_identity_required',
            'Cadastre a identidade fiscal do escritório primeiro.',
        );
    }

    public static function establishmentNotFound(): self
    {
        return new self(
            'tenant_autxml_establishment_not_found',
            'Estabelecimento não encontrado.',
            404,
        );
    }

    public static function inactiveEstablishment(): self
    {
        return new self(
            'tenant_autxml_establishment_inactive',
            'Estabelecimento inativo não pode ser incluído no autXML.',
        );
    }

    public static function enrollmentNotFound(): self
    {
        return new self(
            'tenant_autxml_enrollment_not_found',
            'Adesão autXML não encontrada.',
            404,
        );
    }

    public static function inactiveEnrollment(): self
    {
        return new self(
            'tenant_autxml_enrollment_inactive',
            'Adesão inativa: reative-a como pendente antes de confirmar.',
        );
    }

    public static function streamNotReady(AutXmlStreamData $stream): self
    {
        return new self(
            'tenant_autxml_stream_not_ready',
            'Confirmação bloqueada: ative o stream autXML e aguarde o período mínimo.',
            422,
            ['stream' => $stream->toArray()],
        );
    }

    /** @param array<string, mixed> $responseData */
    private function __construct(
        string $stableCode,
        string $safeMessage,
        int $httpStatus = 422,
        array $responseData = [],
    ) {
        parent::__construct(
            $stableCode,
            $safeMessage,
            $httpStatus,
            $responseData,
        );
    }
}

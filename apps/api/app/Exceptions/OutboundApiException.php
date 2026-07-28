<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class OutboundApiException extends ApiDomainException implements ShouldntReport
{
    public static function invalidSeed(): self
    {
        return new self(
            'outbound_seed_invalid',
            'Semente fiscal inválida para este estabelecimento e ambiente.',
            422,
        );
    }

    public static function invalidCsc(): self
    {
        return new self(
            'outbound_csc_invalid',
            'Configuração de CSC inválida para este perfil.',
            422,
        );
    }

    public static function monthlyExportUnavailable(): self
    {
        return new self(
            'outbound_monthly_export_unavailable',
            'Competência ainda não está pronta para exportação.',
            422,
        );
    }

    public static function invalidOperation(string $code, string $message): self
    {
        return new self($code, $message, 422);
    }

    public static function protocolQueryDisabled(): self
    {
        return new self(
            'outbound_protocol_query_disabled',
            'Consulta de protocolo desabilitada.',
            403,
        );
    }

    private function __construct(string $code, string $message, int $status)
    {
        parent::__construct($code, $message, $status);
    }
}

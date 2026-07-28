<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;

final class EstablishmentApiException extends ApiDomainException implements ShouldntReport
{
    public static function additionalEstablishmentNotSupported(): self
    {
        return new self(
            stableCode: 'additional_establishment_not_supported',
            safeMessage: 'Cada cliente possui um único estabelecimento. Cadastre a filial como um novo cliente.',
            responseData: [
                'errors' => [
                    'cnpj' => [
                        'Use “Novo cliente” com o CNPJ completo da filial. Não se adicionam filiais sob o perfil da matriz.',
                    ],
                ],
            ],
        );
    }

    public static function captureEnableReasonRequired(): self
    {
        return new self(
            stableCode: 'capture_enable_reason_required',
            safeMessage: 'Informe o motivo para habilitar captura com situação cadastral não ativa.',
            responseData: [
                'errors' => [
                    'capture_enable_reason' => [
                        'Motivo obrigatório para habilitação excepcional.',
                    ],
                ],
            ],
        );
    }

    /** @param array<string, mixed> $responseData */
    private function __construct(
        string $stableCode,
        string $safeMessage,
        array $responseData,
    ) {
        parent::__construct(
            $stableCode,
            $safeMessage,
            422,
            $responseData,
        );
    }
}

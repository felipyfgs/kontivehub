<?php

namespace App\Exceptions;

use App\DTO\Esocial\EsocialBxReadiness;
use Illuminate\Contracts\Debug\ShouldntReport;

final class FgtsEsocialApiException extends ApiDomainException implements ShouldntReport
{
    /** @param array<string, mixed> $responseData */
    private function __construct(
        string $code,
        string $message,
        int $status,
        array $responseData = [],
    ) {
        parent::__construct($code, $message, $status, $responseData);
    }

    public static function clientNotFound(): self
    {
        return new self(
            'ESOCIAL_BX_CLIENT_NOT_FOUND',
            'Cliente não encontrado.',
            404,
        );
    }

    public static function establishmentNotFound(): self
    {
        return new self(
            'ESOCIAL_BX_ESTABLISHMENT_NOT_FOUND',
            'Estabelecimento não encontrado.',
            404,
        );
    }

    public static function competenceNotFound(): self
    {
        return new self(
            'ESOCIAL_BX_COMPETENCE_NOT_FOUND',
            'Competência FGTS não encontrada.',
            404,
        );
    }

    public static function runCreationFailed(): self
    {
        return new self(
            'ESOCIAL_RUN_CREATION_FAILED',
            'Não foi possível criar a execução de monitoramento FGTS.',
            422,
        );
    }

    public static function syncFailed(): self
    {
        return new self(
            'ESOCIAL_SYNC_FAILED',
            'Falha sanitizada ao sincronizar o eSocial BX.',
            502,
        );
    }

    public static function syntheticDataQuarantined(): self
    {
        return new self(
            'SYNTHETIC_FISCAL_DATA_QUARANTINED',
            'A sincronização produziu dados sintéticos em quarentena, indisponíveis para uso fiscal.',
            409,
        );
    }

    public static function readinessBlocked(EsocialBxReadiness $readiness): self
    {
        $blocker = $readiness->blockers[0] ?? [
            'code' => 'ESOCIAL_BX_NOT_READY',
            'message' => 'Provider eSocial BX indisponível.',
        ];

        return new self(
            $blocker['code'],
            $blocker['message'],
            self::blockerHttpStatus($blocker['code']),
            ['readiness' => $readiness->toArray()],
        );
    }

    private static function blockerHttpStatus(string $code): int
    {
        if ($code === 'ESOCIAL_BX_QUOTA_EXHAUSTED') {
            return 429;
        }
        if ($code === 'ESOCIAL_BX_CONCURRENT_REQUEST') {
            return 409;
        }
        if (str_starts_with($code, 'ESOCIAL_BX_CREDENTIAL_')) {
            return 422;
        }
        if ($code === 'ESOCIAL_BX_CLIENT_NOT_FOUND') {
            return 404;
        }
        if (in_array($code, [
            'ESOCIAL_BX_DISABLED',
            'ESOCIAL_BX_DRIVER_INVALID',
            'ESOCIAL_BX_ENVIRONMENT_INVALID',
            'ESOCIAL_BX_PRODUCTION_EGRESS_DISABLED',
            'ESOCIAL_BX_ENDPOINT_NOT_ALLOWED',
            'ESOCIAL_BX_LIMITS_INVALID',
            'ESOCIAL_BX_TIMEOUTS_INVALID',
            'ESOCIAL_BX_TIMEZONE_INVALID',
            'ESOCIAL_BX_BLOCKED_DAYS_INVALID',
        ], true)) {
            return 503;
        }

        return 423;
    }
}

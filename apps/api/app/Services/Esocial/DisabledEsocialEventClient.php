<?php

namespace App\Services\Esocial;

use App\Contracts\EsocialEventClient;
use App\DTO\Esocial\EsocialFetchRequest;
use App\DTO\Esocial\EsocialFetchResult;

/**
 * Default fail-closed enquanto não houver uma integração M2M eSocial oficial
 * aprovada. Não realiza transporte, não consulta portal e não simula eventos.
 */
final class DisabledEsocialEventClient implements EsocialEventClient
{
    public const UNAVAILABLE_MESSAGE = 'Integração eSocial BX oficial desabilitada por configuração.';

    public function __construct(
        private readonly string $errorCode = 'ESOCIAL_SOURCE_UNAVAILABLE',
        private readonly string $message = self::UNAVAILABLE_MESSAGE,
    ) {}

    public function fetchEvents(EsocialFetchRequest $request): EsocialFetchResult
    {
        return EsocialFetchResult::failed(
            $this->message,
            $this->errorCode,
        );
    }

    public function unavailableCode(): string
    {
        return $this->errorCode;
    }

    public function unavailableMessage(): string
    {
        return $this->message;
    }
}

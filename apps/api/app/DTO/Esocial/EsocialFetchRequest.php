<?php

namespace App\DTO\Esocial;

use App\Enums\EsocialEventCode;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\Office;
use InvalidArgumentException;

/**
 * Pedido de consulta M2M a eventos eSocial admitidos.
 *
 * @phpstan-type SupportedCodes list<EsocialEventCode>
 */
final readonly class EsocialFetchRequest
{
    /**
     * @param  list<EsocialEventCode>  $eventCodes
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public Office $office,
        public Client $client,
        public string $competencePeriodKey,
        public ?Establishment $establishment = null,
        public array $eventCodes = [],
        public ?string $correlationId = null,
        public array $context = [],
    ) {
        if ((int) $this->client->office_id !== (int) $this->office->id) {
            throw new InvalidArgumentException('Cliente eSocial não pertence ao office informado.');
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->competencePeriodKey) !== 1) {
            throw new InvalidArgumentException('Competência eSocial inválida.');
        }
        if ($this->establishment !== null
            && ((int) $this->establishment->office_id !== (int) $this->office->id
                || (int) $this->establishment->client_id !== (int) $this->client->id)) {
            throw new InvalidArgumentException('Estabelecimento eSocial não pertence ao tenant/cliente informado.');
        }
        if (array_filter($this->eventCodes, static fn (mixed $code): bool => ! $code instanceof EsocialEventCode) !== []) {
            throw new InvalidArgumentException('Lista de eventos eSocial inválida.');
        }
        if ($this->correlationId !== null
            && (strlen($this->correlationId) > 64 || preg_match('/[\r\n]/', $this->correlationId) === 1)) {
            throw new InvalidArgumentException('Correlation ID eSocial inválido.');
        }
    }

    /**
     * @return list<EsocialEventCode>
     */
    public function resolvedEventCodes(): array
    {
        if ($this->eventCodes !== []) {
            return $this->eventCodes;
        }

        return EsocialEventCode::supported();
    }
}

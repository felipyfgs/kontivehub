<?php

namespace App\Services\Communication\Events;

use App\DTO\Communication\GatewayEventData;
use App\Enums\Communication\GatewayEventType;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use InvalidArgumentException;
use Throwable;

final readonly class CommunicationGatewayEventBoundaryValidator
{
    /** @var list<string> */
    private const ROOT_FIELDS = [
        'contract_version',
        'gateway_event_id',
        'session_id',
        'type',
        'occurred_at',
        'payload',
    ];

    public function __construct(
        private ValidationFactory $validation,
    ) {}

    public function validate(string $body): GatewayEventData
    {
        try {
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || array_is_list($payload)) {
                throw new InvalidArgumentException('Evento do gateway deve ser um objeto JSON.');
            }

            if (array_diff(array_keys($payload), self::ROOT_FIELDS) !== []) {
                throw new InvalidArgumentException('Evento do gateway contém campos desconhecidos.');
            }

            $validator = $this->validation->make($payload, [
                'contract_version' => ['required', 'in:v1'],
                'gateway_event_id' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/'],
                'session_id' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/'],
                'type' => ['required', 'string'],
                'occurred_at' => ['required', 'date'],
                'payload' => ['required', 'array'],
            ]);
            if ($validator->fails()) {
                throw new InvalidArgumentException('Evento do gateway não corresponde ao contrato.');
            }

            $type = GatewayEventType::tryFrom((string) $payload['type']);
            if ($type === null) {
                throw new InvalidArgumentException('Tipo de evento do gateway inválido.');
            }

            return new GatewayEventData(
                gatewayEventId: (string) $payload['gateway_event_id'],
                sessionId: (string) $payload['session_id'],
                type: $type,
                occurredAt: new DateTimeImmutable((string) $payload['occurred_at']),
                payload: $payload['payload'],
            );
        } catch (InvalidArgumentException $error) {
            throw $error;
        } catch (Throwable $error) {
            throw new InvalidArgumentException(
                'Evento do gateway inválido.',
                previous: $error,
            );
        }
    }
}

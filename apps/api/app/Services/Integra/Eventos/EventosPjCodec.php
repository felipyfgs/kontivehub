<?php

namespace App\Services\Integra\Eventos;

use InvalidArgumentException;

final class EventosPjCodec
{
    public const VERSION = 'pj-v1-2026-04-10';

    public function __construct(
        private readonly ?string $eventField = null,
    ) {}

    /** @return array<string, string> */
    public function solicit(string $evento): array
    {
        return [$this->resolvedEventField() => $this->normalizeEvent($evento)];
    }

    /** @return array{protocolo: string, evento: string} */
    public function obtain(string $protocol, string $evento): array
    {
        $protocol = trim($protocol);
        if ($protocol === '') {
            throw new InvalidArgumentException('EVENTOS_PROTOCOL_MISSING.');
        }

        return ['protocolo' => $protocol, 'evento' => $this->normalizeEvent($evento)];
    }

    public function resolvedEventField(): string
    {
        $field = $this->eventField ?? (string) config('serpro.eventos.pj_event_field', '');
        if (! in_array($field, ['evento', 'eventValue'], true)) {
            throw new InvalidArgumentException('EVENTOS_PJ_CONTRACT_UNRECONCILED: campo PJ inválido.');
        }

        return $field;
    }

    private function normalizeEvent(string $evento): string
    {
        $evento = strtoupper(trim($evento));
        if (! preg_match('/^E[0-9]{4}$/', $evento)) {
            throw new InvalidArgumentException('EVENTOS_EVENT_INVALID.');
        }

        return $evento;
    }
}

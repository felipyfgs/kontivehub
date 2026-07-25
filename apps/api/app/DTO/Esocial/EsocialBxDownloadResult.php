<?php

declare(strict_types=1);

namespace App\DTO\Esocial;

use InvalidArgumentException;

final readonly class EsocialBxDownloadResult
{
    /** @param list<EsocialEventDto> $events */
    public function __construct(
        public array $events,
        public bool $partial,
        public string $officialCode,
    ) {
        if (count($this->events) > 50
            || array_filter($this->events, static fn (mixed $event): bool => ! $event instanceof EsocialEventDto) !== []) {
            throw new InvalidArgumentException('Resultado de download eSocial BX inválido.');
        }
        if (preg_match('/^\d{3}$/', $this->officialCode) !== 1) {
            throw new InvalidArgumentException('Código oficial eSocial BX inválido.');
        }
    }

    /** @return array{count:int,partial:bool,official_code:string} */
    public function toSanitizedArray(): array
    {
        return [
            'count' => count($this->events),
            'partial' => $this->partial,
            'official_code' => $this->officialCode,
        ];
    }
}

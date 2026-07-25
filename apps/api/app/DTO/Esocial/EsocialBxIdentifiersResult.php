<?php

declare(strict_types=1);

namespace App\DTO\Esocial;

use InvalidArgumentException;

final readonly class EsocialBxIdentifiersResult
{
    /** @param list<EsocialBxIdentifier> $identifiers */
    public function __construct(
        public array $identifiers,
        public bool $partial,
        public string $officialCode,
    ) {
        if (count($this->identifiers) > 50
            || array_filter($this->identifiers, static fn (mixed $item): bool => ! $item instanceof EsocialBxIdentifier) !== []) {
            throw new InvalidArgumentException('Resultado de identificadores eSocial BX inválido.');
        }
        if (preg_match('/^\d{3}$/', $this->officialCode) !== 1) {
            throw new InvalidArgumentException('Código oficial eSocial BX inválido.');
        }
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_map(static fn (EsocialBxIdentifier $identifier): string => $identifier->id, $this->identifiers);
    }

    /** @return array<string, string|null> */
    public function receiptsById(): array
    {
        $receipts = [];
        foreach ($this->identifiers as $identifier) {
            $receipts[$identifier->id] = $identifier->receipt;
        }

        return $receipts;
    }

    /** @return array{count:int,partial:bool,official_code:string} */
    public function toSanitizedArray(): array
    {
        return [
            'count' => count($this->identifiers),
            'partial' => $this->partial,
            'official_code' => $this->officialCode,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\DTO\Esocial;

use InvalidArgumentException;

final readonly class EsocialBxIdentifier
{
    public function __construct(
        public string $id,
        public ?string $receipt = null,
    ) {
        if (preg_match('/^ID[0-9A-Za-z_-]{10,80}$/', $this->id) !== 1) {
            throw new InvalidArgumentException('Identificador de evento eSocial BX inválido.');
        }
        if ($this->receipt !== null && (strlen($this->receipt) > 80 || preg_match('/[\r\n]/', $this->receipt) === 1)) {
            throw new InvalidArgumentException('Recibo de evento eSocial BX inválido.');
        }
    }

    /** @return array{event_id_hash:string,has_receipt:bool} */
    public function toSanitizedArray(): array
    {
        return [
            'event_id_hash' => hash('sha256', $this->id),
            'has_receipt' => $this->receipt !== null,
        ];
    }
}

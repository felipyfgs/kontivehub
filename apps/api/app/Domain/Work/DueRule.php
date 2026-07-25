<?php

namespace App\Domain\Work;

use App\Enums\Work\DueRuleType;
use InvalidArgumentException;

/**
 * Regra de prazo tipada do modelo (payload validado).
 */
final readonly class DueRule
{
    public function __construct(
        public DueRuleType $type,
        public int $value,
    ) {
        if ($this->value < 0) {
            throw new InvalidArgumentException('Valor da regra de prazo não pode ser negativo.');
        }

        if ($this->type === DueRuleType::FixedDayOfCompetence && ($this->value < 1 || $this->value > 31)) {
            throw new InvalidArgumentException('Dia fixo da competência deve estar entre 1 e 31.');
        }
    }

    /**
     * @param  array{type?: string, value?: int|string}|null  $raw
     */
    public static function tryFromArray(?array $raw): ?self
    {
        if ($raw === null || $raw === []) {
            return null;
        }

        $type = DueRuleType::from((string) ($raw['type'] ?? ''));
        $value = (int) ($raw['value'] ?? 0);

        return new self($type, $value);
    }

    /**
     * @return array{type: string, value: int}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'value' => $this->value,
        ];
    }
}

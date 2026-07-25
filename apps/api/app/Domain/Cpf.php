<?php

namespace App\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * CPF textual de 11 dígitos. Armazenar sem máscara; nunca como número.
 */
final class Cpf implements Stringable
{
    private function __construct(private readonly string $value) {}

    public static function normalize(string $raw): string
    {
        return preg_replace('/\D/', '', $raw) ?? '';
    }

    public static function tryParse(string $raw): ?self
    {
        try {
            return self::parse($raw);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function parse(string $raw): self
    {
        $value = self::normalize($raw);

        if (strlen($value) !== 11 || ! ctype_digit($value)) {
            throw new InvalidArgumentException('CPF deve ter 11 dígitos após normalização.');
        }

        if ($value === str_repeat($value[0], 11)) {
            throw new InvalidArgumentException('CPF inválido.');
        }

        if (! self::hasValidCheckDigits($value)) {
            throw new InvalidArgumentException('Dígitos verificadores do CPF são inválidos.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function hasValidCheckDigits(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || ! ctype_digit($cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }
}

<?php

namespace App\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * CNPJ textual de 14 caracteres (numérico ou alfanumérico).
 * Armazenar sempre maiúsculo, sem máscara; nunca como número.
 */
final class Cnpj implements Stringable
{
    private function __construct(private readonly string $value) {}

    /**
     * Normaliza para 14 chars alfanuméricos maiúsculos (sem máscara).
     * Nunca coerção numérica — zeros e letras são preservados.
     */
    public static function normalize(string $raw): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $raw) ?? '');
    }

    /**
     * Round-trip seguro para persistência/JSON/XML/cache.
     */
    public function toStorageString(): string
    {
        return $this->value;
    }

    public static function fromStorageString(string $stored): self
    {
        return self::parse($stored);
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

        if (strlen($value) !== 14) {
            throw new InvalidArgumentException('CNPJ deve ter 14 caracteres após normalização.');
        }

        if (! preg_match('/^[0-9A-Z]{14}$/', $value)) {
            throw new InvalidArgumentException('CNPJ contém caracteres inválidos.');
        }

        if (! self::hasValidCheckDigits($value)) {
            throw new InvalidArgumentException('Dígitos verificadores do CNPJ são inválidos.');
        }

        // Rejeita sequência trivial só de zeros (comum em máscaras vazias)
        if ($value === str_repeat('0', 14)) {
            throw new InvalidArgumentException('CNPJ inválido.');
        }

        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function root(): string
    {
        return substr($this->value, 0, 8);
    }

    public function sameRootAs(self $other): bool
    {
        return $this->root() === $other->root();
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Módulo 11 com suporte alfanumérico (valor = ord(c) - 48).
     */
    public static function hasValidCheckDigits(string $cnpj): bool
    {
        if (strlen($cnpj) !== 14) {
            return false;
        }

        $cnpj = strtoupper($cnpj);
        $base = substr($cnpj, 0, 12);
        $d1 = self::checkDigit($base, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        $d2 = self::checkDigit($base.$d1, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

        return $cnpj[12] === $d1 && $cnpj[13] === $d2;
    }

    /**
     * @param  list<int>  $weights
     */
    private static function checkDigit(string $base, array $weights): string
    {
        $sum = 0;
        $len = strlen($base);

        for ($i = 0; $i < $len; $i++) {
            $sum += self::charValue($base[$i]) * $weights[$i];
        }

        $mod = $sum % 11;
        $digit = $mod < 2 ? 0 : 11 - $mod;

        return (string) $digit;
    }

    private static function charValue(string $char): int
    {
        return ord($char) - 48;
    }
}

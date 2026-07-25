<?php

namespace App\Domain;

use InvalidArgumentException;
use Stringable;

/**
 * Normalizador/validador único de CPF/CNPJ (inclui CNPJ alfanumérico).
 * Preserva maiúsculas; remove máscara; nunca coerção numérica.
 */
final class BrazilianTaxId implements Stringable
{
    public const KIND_CPF = 'CPF';

    public const KIND_CNPJ = 'CNPJ';

    private function __construct(
        private readonly string $kind,
        private readonly string $value,
    ) {}

    /**
     * Remove máscara e normaliza (CNPJ uppercase; CPF só dígitos).
     */
    public static function normalize(string $raw): string
    {
        $clean = preg_replace('/[^0-9A-Za-z]/', '', $raw) ?? '';

        return strtoupper($clean);
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
        $normalized = self::normalize($raw);

        if (strlen($normalized) === 11 && ctype_digit($normalized)) {
            $cpf = Cpf::parse($normalized);

            return new self(self::KIND_CPF, $cpf->value());
        }

        if (strlen($normalized) === 14) {
            $cnpj = Cnpj::parse($normalized);

            return new self(self::KIND_CNPJ, $cnpj->value());
        }

        throw new InvalidArgumentException(
            'Identidade fiscal inválida (esperado CPF 11 dígitos ou CNPJ 14 caracteres alfanuméricos).'
        );
    }

    public static function parseCpf(string $raw): self
    {
        $cpf = Cpf::parse($raw);

        return new self(self::KIND_CPF, $cpf->value());
    }

    public static function parseCnpj(string $raw): self
    {
        $cnpj = Cnpj::parse($raw);

        return new self(self::KIND_CNPJ, $cnpj->value());
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function isCpf(): bool
    {
        return $this->kind === self::KIND_CPF;
    }

    public function isCnpj(): bool
    {
        return $this->kind === self::KIND_CNPJ;
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Serialização para JSON/XML/cache — sempre string canônica, sem coerção.
     *
     * @return array{kind: string, value: string}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'value' => $this->value,
        ];
    }

    /**
     * @param  array{kind?: string, value?: string}|string  $payload
     */
    public static function fromArrayOrString(array|string $payload): self
    {
        if (is_string($payload)) {
            return self::parse($payload);
        }

        $value = (string) ($payload['value'] ?? '');
        $kind = strtoupper((string) ($payload['kind'] ?? ''));

        return match ($kind) {
            self::KIND_CPF => self::parseCpf($value),
            self::KIND_CNPJ => self::parseCnpj($value),
            default => self::parse($value),
        };
    }
}

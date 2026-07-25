<?php

namespace App\DTO\Serpro;

use App\Domain\BrazilianTaxId;
use App\Domain\Cnpj;
use App\Domain\Cpf;
use App\Enums\AuthorIdentityType;
use InvalidArgumentException;

/**
 * Identidade fiscal tipada (CPF/CNPJ) para o envelope Integra Contador.
 * NI é sempre texto uppercase; CNPJ alfanumérico é preservado.
 * Delega normalização ao {@see BrazilianTaxId}.
 */
final readonly class FiscalIdentity
{
    public string $numero;

    public function __construct(
        public AuthorIdentityType $tipo,
        string $numero,
    ) {
        $normalized = self::normalizeNumero($numero);
        if ($normalized === '') {
            throw new InvalidArgumentException('Identidade fiscal sem número.');
        }
        if ($tipo === AuthorIdentityType::Cpf) {
            $cpf = Cpf::tryParse($normalized);
            if ($cpf === null) {
                throw new InvalidArgumentException('CPF fiscal inválido (esperado 11 dígitos com DV).');
            }
            $normalized = $cpf->value();
        }
        if ($tipo === AuthorIdentityType::Cnpj) {
            $cnpj = Cnpj::tryParse($normalized);
            if ($cnpj === null) {
                throw new InvalidArgumentException('CNPJ fiscal inválido (esperado 14 caracteres alfanuméricos com DV).');
            }
            $normalized = $cnpj->value();
        }
        if (count(array_unique(str_split($normalized))) === 1) {
            throw new InvalidArgumentException('Identidade fiscal placeholder não é aceita no gateway.');
        }
        $this->numero = $normalized;
    }

    /**
     * Factory a partir de NI textual (detecta CPF vs CNPJ pelo comprimento).
     */
    public static function fromNumero(string $numero, ?AuthorIdentityType $forceTipo = null): self
    {
        $normalized = self::normalizeNumero($numero);
        if ($normalized === '') {
            throw new InvalidArgumentException('Identidade fiscal sem número.');
        }

        if ($forceTipo !== null) {
            return new self($forceTipo, $normalized);
        }

        if (self::looksLikeCpf($normalized)) {
            return new self(AuthorIdentityType::Cpf, $normalized);
        }
        if (self::looksLikeCnpj($normalized)) {
            return new self(AuthorIdentityType::Cnpj, $normalized);
        }

        throw new InvalidArgumentException('Identidade fiscal inválida (esperado CPF ou CNPJ completo).');
    }

    /**
     * Tipo numérico do envelope SERPRO: 1=CPF, 2=CNPJ.
     */
    public function envelopeTipo(): int
    {
        return $this->tipo === AuthorIdentityType::Cpf ? 1 : 2;
    }

    /**
     * @return array{numero: string, tipo: int}
     */
    public function toEnvelope(): array
    {
        return [
            'numero' => $this->numero,
            'tipo' => $this->envelopeTipo(),
        ];
    }

    public static function normalizeNumero(string $numero): string
    {
        return BrazilianTaxId::normalize($numero);
    }

    public static function looksLikeCpf(string $normalized): bool
    {
        return strlen($normalized) === 11 && ctype_digit($normalized);
    }

    public static function looksLikeCnpj(string $normalized): bool
    {
        // 14 chars; dígitos clássicos ou alfanumérico uppercase
        return strlen($normalized) === 14 && (bool) preg_match('/^[0-9A-Z]{14}$/', $normalized);
    }

    public function toBrazilianTaxId(): BrazilianTaxId
    {
        return $this->tipo === AuthorIdentityType::Cpf
            ? BrazilianTaxId::parseCpf($this->numero)
            : BrazilianTaxId::parseCnpj($this->numero);
    }
}

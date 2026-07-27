<?php

namespace App\Enums;

/**
 * Regime tributário canônico para regras de aplicabilidade e projeções SN/MEI.
 * Nunca misturar competências de regimes distintos.
 */
enum TaxRegimeCode: string
{
    case SimplesNacional = 'SIMPLES_NACIONAL';
    case Mei = 'MEI';
    case LucroPresumido = 'LUCRO_PRESUMIDO';
    case LucroReal = 'LUCRO_REAL';
    case ImuneIsento = 'IMUNE_ISENTO';
    case Outro = 'OUTRO';
    case Unknown = 'UNKNOWN';

    /** Resolve somente códigos canônicos persistidos. */
    public static function normalize(?string $raw): self
    {
        if ($raw === null || trim($raw) === '') {
            return self::Unknown;
        }

        return self::tryFrom(strtoupper(trim($raw))) ?? self::Unknown;
    }

    /** @return list<string> */
    public static function currentProjectionValues(): array
    {
        return [
            self::SimplesNacional->value,
            self::Mei->value,
            self::LucroPresumido->value,
            self::LucroReal->value,
            self::ImuneIsento->value,
            self::Outro->value,
            self::Unknown->value,
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::SimplesNacional => 'Simples Nacional',
            self::Mei => 'MEI / SIMEI',
            self::LucroPresumido => 'Lucro Presumido',
            self::LucroReal => 'Lucro Real',
            self::ImuneIsento => 'Imune / Isento',
            self::Outro => 'Outro regime',
            self::Unknown => 'Desconhecido',
        };
    }

    public function fiscalCategoryCode(): ?string
    {
        return match ($this) {
            self::SimplesNacional => 'SIMPLES_NACIONAL',
            self::Mei => 'MEI',
            default => null,
        };
    }

    public function isSimplesFamily(): bool
    {
        return $this === self::SimplesNacional;
    }

    public function isMeiFamily(): bool
    {
        return $this === self::Mei;
    }

    /** SN e MEI não compartilham projeções de obrigação. */
    public function matches(self $other): bool
    {
        if ($this === self::Unknown || $other === self::Unknown) {
            return false;
        }

        return $this === $other;
    }
}

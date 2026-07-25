<?php

namespace App\Enums;

/**
 * Regime tributário canônico para regras de aplicabilidade e projeções SN/MEI.
 * Valores livres em clients.tax_regime são normalizados via normalize().
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

    /**
     * Normaliza string livre (cadastro do cliente) para código canônico.
     */
    public static function normalize(?string $raw): self
    {
        if ($raw === null || trim($raw) === '') {
            return self::Unknown;
        }

        $key = mb_strtoupper(trim($raw));
        $key = strtr($key, [
            'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A',
            'É' => 'E', 'Ê' => 'E',
            'Í' => 'I',
            'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O',
            'Ú' => 'U',
            'Ç' => 'C',
        ]);
        $key = preg_replace('/[^A-Z0-9]+/', '_', $key) ?? $key;
        $key = trim($key, '_');

        return match ($key) {
            'SIMPLES_NACIONAL', 'SIMPLES', 'SN', 'SIMPLES_NAC' => self::SimplesNacional,
            'MEI', 'SIMEI', 'MICROEMPREENDEDOR_INDIVIDUAL' => self::Mei,
            'LUCRO_PRESUMIDO', 'PRESUMIDO', 'LP' => self::LucroPresumido,
            'LUCRO_REAL', 'REAL', 'LR' => self::LucroReal,
            'IMUNE_ISENTO', 'IMUNE', 'ISENTO', 'IMUNES_ISENTOS' => self::ImuneIsento,
            'OUTRO', 'OTHER' => self::Outro,
            default => self::tryFrom($key) ?? self::Unknown,
        };
    }

    /**
     * Normaliza entradas de cadastro para a projeção atual de clients.tax_regime.
     * Vazio permanece null; rótulos não reconhecidos são preservados como OUTRO.
     */
    public static function fromInput(?string $raw): ?self
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $normalized = self::normalize($raw);

        return $normalized === self::Unknown ? self::Outro : $normalized;
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
        ];
    }

    /**
     * Valores de coluna que devem casar ao filtrar por este código canônico
     * (inclui aliases legados ainda possíveis em clients.tax_regime).
     *
     * @return list<string>
     */
    public function storageFilterValues(): array
    {
        $aliases = match ($this) {
            self::SimplesNacional => [
                'SIMPLES',
                'SN',
                'SIMPLES_NAC',
                'Simples Nacional',
                'SIMPLES NACIONAL',
            ],
            self::Mei => [
                'SIMEI',
                'MICROEMPREENDEDOR_INDIVIDUAL',
                'MEI / SIMEI',
            ],
            self::LucroPresumido => [
                'PRESUMIDO',
                'LP',
                'Lucro Presumido',
                'LUCRO PRESUMIDO',
            ],
            self::LucroReal => [
                'REAL',
                'LR',
                'Lucro Real',
                'LUCRO REAL',
            ],
            self::ImuneIsento => [
                'IMUNE',
                'ISENTO',
                'IMUNES_ISENTOS',
                'Imune / Isento',
                'IMUNE / ISENTO',
            ],
            self::Outro => [
                'OTHER',
                'Outro regime',
                'OUTRO REGIME',
            ],
            self::Unknown => [],
        };

        return array_values(array_unique([$this->value, ...$aliases]));
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
    public function compatibleWith(self $other): bool
    {
        if ($this === self::Unknown || $other === self::Unknown) {
            return false;
        }

        return $this === $other;
    }
}

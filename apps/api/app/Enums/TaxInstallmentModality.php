<?php

namespace App\Enums;

/**
 * Modalidades oficiais Integra-Parcelamento (idSistema SERPRO).
 *
 * @see https://apicenter.estaleiro.serpro.gov.br/documentacao/api-integra-contador/pt/solucoes/integra-parcelamento/
 */
enum TaxInstallmentModality: string
{
    case Parcsn = 'PARCSN';
    case ParcsnEsp = 'PARCSN-ESP';
    case Pertsn = 'PERTSN';
    case Relpsn = 'RELPSN';
    case Parcmei = 'PARCMEI';
    case ParcmeiEsp = 'PARCMEI-ESP';
    case Pertmei = 'PERTMEI';
    case Relpmei = 'RELPMEI';

    public function label(): string
    {
        return match ($this) {
            self::Parcsn => 'Parcelamento SN — Ordinário',
            self::ParcsnEsp => 'Parcelamento SN — Especial',
            self::Pertsn => 'PERT SN',
            self::Relpsn => 'RELP SN',
            self::Parcmei => 'Parcelamento MEI — Ordinário',
            self::ParcmeiEsp => 'Parcelamento MEI — Especial',
            self::Pertmei => 'PERT MEI',
            self::Relpmei => 'RELP MEI',
        };
    }

    /** Regime fiscal de origem (nunca misturar SN e MEI). */
    public function regime(): string
    {
        return match ($this) {
            self::Parcsn, self::ParcsnEsp, self::Pertsn, self::Relpsn => 'SN',
            self::Parcmei, self::ParcmeiEsp, self::Pertmei, self::Relpmei => 'MEI',
        };
    }

    public function isSn(): bool
    {
        return $this->regime() === 'SN';
    }

    public function isMei(): bool
    {
        return $this->regime() === 'MEI';
    }

    /** Código de poder de procuração exigido (por modalidade). */
    public function requiredPowerCode(): string
    {
        return $this->value;
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return self::cases();
    }

    /**
     * @return list<self>
     */
    public static function forRegime(string $regime): array
    {
        $regime = strtoupper($regime);

        return array_values(array_filter(
            self::cases(),
            fn (self $m) => $m->regime() === $regime,
        ));
    }
}

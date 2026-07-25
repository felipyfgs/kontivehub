<?php

namespace App\Enums;

/**
 * Classe de consumo faturável do SERPRO / Integra Contador.
 */
enum SerproConsumptionClass: string
{
    case Consulta = 'CONSULTA';
    case Emissao = 'EMISSAO';
    case Declaracao = 'DECLARACAO';
    case NaoFaturavel = 'NAO_FATURAVEL';
    case Desconhecida = 'DESCONHECIDA';

    public function isBillable(): bool
    {
        return match ($this) {
            self::Consulta, self::Emissao, self::Declaracao => true,
            self::NaoFaturavel => false,
            // DESCONHECIDA: possivelmente faturável — não inventar zero
            self::Desconhecida => true,
        };
    }

    /**
     * Custo estimado pode ser calculado (faixas de preço)?
     * DESCONHECIDA não inventa custo zero.
     */
    public function allowsCostEstimate(): bool
    {
        return match ($this) {
            self::Consulta, self::Emissao, self::Declaracao => true,
            self::NaoFaturavel => true, // estimativa explícita 0
            self::Desconhecida => false,
        };
    }

    public function isUnknown(): bool
    {
        return $this === self::Desconhecida;
    }
}

<?php

namespace App\Enums;

/**
 * Tipos de Manifestação do Destinatário (tpEvento SEFAZ).
 */
enum NfeManifestationType: string
{
    case Ciencia = 'CIENCIA';
    case Confirmacao = 'CONFIRMACAO';
    case Desconhecimento = 'DESCONHECIMENTO';
    case NaoRealizada = 'NAO_REALIZADA';

    public function tpEvento(): string
    {
        return match ($this) {
            self::Ciencia => '210210',
            self::Confirmacao => '210200',
            self::Desconhecimento => '210220',
            self::NaoRealizada => '210240',
        };
    }

    /** descEvento sem acento (padrão SEFAZ). */
    public function descEvento(): string
    {
        return match ($this) {
            self::Ciencia => 'Ciencia da Operacao',
            self::Confirmacao => 'Confirmacao da Operacao',
            self::Desconhecimento => 'Desconhecimento da Operacao',
            self::NaoRealizada => 'Operacao nao Realizada',
        };
    }

    public function isConclusive(): bool
    {
        return $this !== self::Ciencia;
    }

    public function requiresJustification(): bool
    {
        return $this === self::NaoRealizada;
    }

    public function manifestationStatus(): string
    {
        return match ($this) {
            self::Ciencia => 'CIENCIA_REGISTRADA',
            self::Confirmacao => 'CONFIRMADA',
            self::Desconhecimento => 'DESCONHECIDA',
            self::NaoRealizada => 'NAO_REALIZADA',
        };
    }

    public static function tryFromInput(string $value): ?self
    {
        $v = strtoupper(trim($value));

        return match ($v) {
            'CIENCIA', '210210' => self::Ciencia,
            'CONFIRMACAO', 'CONFIRMAÇÃO', '210200' => self::Confirmacao,
            'DESCONHECIMENTO', '210220' => self::Desconhecimento,
            'NAO_REALIZADA', 'NAO REALIZADA', 'NÃO_REALIZADA', '210240' => self::NaoRealizada,
            default => self::tryFrom($v),
        };
    }
}

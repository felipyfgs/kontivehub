<?php

namespace App\Enums;

enum PgdasdOperationKind: string
{
    case Declaration = 'DECLARATION';
    case Das = 'DAS';

    public function isDeclaration(): bool
    {
        return $this === self::Declaration;
    }

    public function isDas(): bool
    {
        return $this === self::Das;
    }

    public static function fromObservation(?string $raw, bool $hasDeclaration, bool $hasDas): ?self
    {
        if ($hasDeclaration && ! $hasDas) {
            return self::Declaration;
        }
        if ($hasDas && ! $hasDeclaration) {
            return self::Das;
        }

        $normalized = mb_strtolower(trim((string) $raw));
        if (str_contains($normalized, 'declara')) {
            return self::Declaration;
        }
        if (str_contains($normalized, 'das') || str_contains($normalized, 'gera')) {
            return self::Das;
        }

        return null;
    }

    public static function normalizedOperationType(?string $raw, self $kind): string
    {
        $normalized = mb_strtolower(trim((string) $raw));

        if ($kind === self::Declaration) {
            return str_contains($normalized, 'retific') ? 'RECTIFIER' : 'ORIGINAL';
        }

        return match (true) {
            str_contains($normalized, 'avulso') => 'DAS_AVULSO',
            str_contains($normalized, 'cobran') => 'DAS_COBRANCA',
            str_contains($normalized, 'medida'), str_contains($normalized, 'judicial') => 'DAS_MEDIDA_JUDICIAL',
            default => 'DAS',
        };
    }
}

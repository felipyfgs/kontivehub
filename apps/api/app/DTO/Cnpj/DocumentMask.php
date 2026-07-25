<?php

namespace App\DTO\Cnpj;

/**
 * Garante que documentos de QSA nunca entrem no snapshot em claro.
 */
final class DocumentMask
{
    public static function ensureMasked(mixed $value): ?string
    {
        if ($value === null || is_array($value) || is_object($value)) {
            return null;
        }

        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        if (str_contains($raw, '*')) {
            return $raw;
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) === 11) {
            return '***'.substr($digits, 3, 6).'**';
        }
        if (strlen($digits) === 14) {
            return '***'.substr($digits, 2, 8).'**';
        }

        // Valor não reconhecido: não persistir em claro
        if (preg_match('/^\d{8,}$/', $digits)) {
            $len = strlen($digits);

            return '***'.substr($digits, (int) floor($len * 0.25), (int) max(4, floor($len * 0.5))).'**';
        }

        return null;
    }
}

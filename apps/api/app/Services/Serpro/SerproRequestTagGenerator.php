<?php

namespace App\Services\Serpro;

use Illuminate\Support\Str;

/**
 * Gera X-Request-Tag opaca (≤32 chars), sem PII, distinta da idempotency key.
 *
 * A tag correlaciona ledger/CSV/logs sanitizados; NÃO é garantia de idempotência.
 */
final class SerproRequestTagGenerator
{
    public const MAX_LENGTH = 32;

    /**
     * Tag opaca determinística a partir de seed interno (sem CNPJ/CPF/nome).
     *
     * @param  array<string, scalar|null>  $opaqueParts  office id, op hash, etc. — nunca NI
     */
    public function generate(array $opaqueParts = []): string
    {
        $seed = implode('|', array_map(
            static fn ($v): string => $v === null ? '' : (string) $v,
            $opaqueParts,
        ));

        if ($seed === '') {
            $seed = (string) Str::uuid();
        }

        // Prefixo "ic" (integra correlation) + hex — total ≤ 32, sem PII.
        // Re-hashea se a amostra hex contiver 11+ dígitos seguidos (heurística anti-PII).
        for ($i = 0; $i < 16; $i++) {
            $hash = hash('sha256', $seed.'|'.$i);
            $tag = substr('ic'.$hash, 0, self::MAX_LENGTH);
            if (preg_match('/\d{11,}/', $tag) !== 1 && ! str_contains($tag, '@')) {
                return $tag;
            }
        }

        // Fallback determinístico: mistura letras para quebrar runs numéricos.
        $fallback = hash('sha256', $seed.'|fallback');
        $mixed = preg_replace_callback('/\d{11,}/', static function (array $m): string {
            return substr(str_replace(
                ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
                ['a', 'b', 'c', 'd', 'e', 'f', 'a', 'b', 'c', 'd'],
                $m[0],
            ), 0, strlen($m[0]));
        }, $fallback) ?? $fallback;

        return substr('ic'.$mixed, 0, self::MAX_LENGTH);
    }

    /**
     * Tag aleatória opaca (para tentativas sem seed estável).
     */
    public function random(): string
    {
        for ($i = 0; $i < 8; $i++) {
            $tag = substr('ic'.bin2hex(random_bytes(16)), 0, self::MAX_LENGTH);
            if (preg_match('/\d{11,}/', $tag) !== 1) {
                return $tag;
            }
        }

        return substr('ic'.str_replace(range('0', '9'), range('a', 'j'), bin2hex(random_bytes(16))), 0, self::MAX_LENGTH);
    }

    public function assertOpaque(string $tag): void
    {
        if (strlen($tag) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('X-Request-Tag excede 32 caracteres.');
        }

        // Heurística: bloquear sequências longas de dígitos (possível NI) e @.
        if (preg_match('/\d{11,}/', $tag) === 1 || str_contains($tag, '@')) {
            throw new \InvalidArgumentException('X-Request-Tag não pode conter PII.');
        }
    }
}

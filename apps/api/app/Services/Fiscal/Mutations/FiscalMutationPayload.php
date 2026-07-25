<?php

namespace App\Services\Fiscal\Mutations;

/** Canonicaliza o payload fiscal para vincular preflight e execução. */
final class FiscalMutationPayload
{
    /** @param array<string, mixed> $payload */
    public static function digest(array $payload): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return $value;
    }
}

<?php

namespace App\DTO\Communication;

final class CommunicationPayloadDigest
{
    /** @param array<string, mixed> $payload */
    public static function make(array $payload): string
    {
        return hash('sha256', json_encode(
            self::normalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private static function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::normalize(...), $value);
    }
}

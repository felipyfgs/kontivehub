<?php

namespace App\Services\Integra;

use RuntimeException;

/** Parser estrito do ETag sensível usado exclusivamente pelo Autentica Procurador. */
final class AutenticaProcuradorEtag
{
    private const PREFIX = 'autenticar_procurador_token:';

    public static function assertValidCondition(string $etag): string
    {
        if ($etag === ''
            || strlen($etag) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $etag) === 1
            || self::extractToken($etag) === null
        ) {
            throw new RuntimeException('If-None-Match interno inválido.');
        }

        return $etag;
    }

    public static function extractToken(?string $etag): ?string
    {
        if ($etag === null
            || strlen($etag) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $etag) === 1
        ) {
            return null;
        }

        $value = trim($etag);
        if (str_starts_with($value, 'W/')) {
            $value = substr($value, 2);
        }
        $value = trim($value, " \t\n\r\0\x0B\"");
        if (! str_starts_with(strtolower($value), self::PREFIX)) {
            return null;
        }

        $token = trim(substr($value, strlen(self::PREFIX)));
        if ($token === '' || strlen($token) > 4096 || preg_match('/[\x00-\x20\x7F]/', $token) === 1) {
            return null;
        }

        return $token;
    }
}

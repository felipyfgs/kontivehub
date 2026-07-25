<?php

namespace App\Services\Communication\Security;

use InvalidArgumentException;

final class CommunicationHmacCanonicalizer
{
    public function canonical(string $method, string $path, string $body, int $timestamp, string $nonce): string
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\n")) {
            throw new InvalidArgumentException('Path HMAC inválido.');
        }

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{15,127}$/', $nonce)) {
            throw new InvalidArgumentException('Nonce HMAC inválido.');
        }

        return implode("\n", [
            strtoupper(trim($method)),
            $path,
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }
}

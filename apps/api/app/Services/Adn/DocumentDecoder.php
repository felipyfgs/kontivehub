<?php

namespace App\Services\Adn;

use App\Exceptions\Adn\DocumentDecodeException;

final class DocumentDecoder
{
    /**
     * @return array{bytes: string, sha256: string}
     */
    public function decodeBase64Gzip(string $contentBase64): array
    {
        $binary = base64_decode($contentBase64, true);
        if ($binary === false) {
            throw new DocumentDecodeException('Base64 inválido.');
        }

        set_error_handler(static fn (): bool => true);

        try {
            $decoded = gzdecode($binary);
        } finally {
            restore_error_handler();
        }

        if ($decoded === false || $decoded === '') {
            throw new DocumentDecodeException('GZip inválido.');
        }

        return [
            'bytes' => $decoded,
            'sha256' => hash('sha256', $decoded),
        ];
    }
}

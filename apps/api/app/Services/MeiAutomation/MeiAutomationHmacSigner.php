<?php

namespace App\Services\MeiAutomation;

use Illuminate\Support\Str;
use RuntimeException;

final class MeiAutomationHmacSigner
{
    /** @return array<string, string> */
    public function headers(string $method, string $path, string $body = ''): array
    {
        $keyId = trim((string) config('mei_automation.hmac.key_id'));
        $secret = (string) config('mei_automation.hmac.secret');
        if ($keyId === '' || $secret === '') {
            throw new RuntimeException('HMAC da automação MEI não configurado.');
        }

        $timestamp = (string) now()->getTimestamp();
        $nonce = (string) Str::uuid();
        $canonical = implode("\n", [
            strtoupper($method),
            $path,
            hash('sha256', $body),
            $timestamp,
            $nonce,
        ]);

        return [
            'X-MEI-Key-Id' => $keyId,
            'X-MEI-Timestamp' => $timestamp,
            'X-MEI-Nonce' => $nonce,
            'X-MEI-Signature' => hash_hmac('sha256', $canonical, $secret),
        ];
    }
}

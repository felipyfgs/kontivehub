<?php

namespace App\Services\MeiAutomation;

final class MeiAutomationMetadataSanitizer
{
    /** @var list<string> */
    private const SAFE_KEYS = [
        'action_type',
        'artifact_count',
        'coverage',
        'http_status',
        'latency_ms',
        'parser_version',
        'portal_version',
        'result_scope',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, bool|float|int|string|null>
     */
    public function sanitize(array $metadata): array
    {
        $safe = [];
        foreach (self::SAFE_KEYS as $key) {
            $value = $metadata[$key] ?? null;
            if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
                if (array_key_exists($key, $metadata)) {
                    $safe[$key] = $value;
                }

                continue;
            }
            if (is_string($value)) {
                $safe[$key] = mb_substr($this->redact($value), 0, 160);
            }
        }

        return $safe;
    }

    public function error(?string $message): ?string
    {
        if ($message === null || trim($message) === '') {
            return null;
        }

        return mb_substr($this->redact($message), 0, 240);
    }

    private function redact(string $value): string
    {
        $patterns = [
            '/\b[A-Z0-9]{14}\b/i' => '[CNPJ_REDACTED]',
            '/\b\d{2}[.\s]?\d{3}[.\s]?\d{3}[\/]?\d{4}[-\s]?\d{2}\b/' => '[CNPJ_REDACTED]',
            '/\b(bearer|token|password|senha|captcha)\s*[:=]\s*[^\s,;]+/i' => '$1=[REDACTED]',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $value);
    }
}

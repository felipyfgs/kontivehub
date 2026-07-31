<?php

namespace App\Services\Communication\Flows;

use App\Support\LogSanitizer;

/**
 * Máscara de PII/segredos em textos do grafo de fluxos (preview / dry-run).
 * Nunca deve ser usada para persistir o grafo — apenas projeção de leitura.
 */
final class FlowTextMasker
{
    public function mask(string $text): string
    {
        $scrubbed = LogSanitizer::scrubString($text);
        if ($scrubbed !== $text && str_starts_with($scrubbed, 'Mensagem sanitizada')) {
            return $scrubbed;
        }

        $masked = $scrubbed;
        $masked = preg_replace(
            '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/i',
            '[email-mascarado]',
            $masked,
        ) ?? $masked;
        $masked = preg_replace(
            '/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/',
            '***.***.***-**',
            $masked,
        ) ?? $masked;
        $masked = preg_replace(
            '/\b\d{2}\.?\d{3}\.?\d{3}\/?\d{4}-?\d{2}\b/',
            '**.***.***/****-**',
            $masked,
        ) ?? $masked;
        $masked = preg_replace_callback(
            '/(?:\+?\d[\d\s\-()]{7,}\d)/u',
            function (array $m): string {
                $digits = preg_replace('/\D+/', '', $m[0]) ?? '';
                if (strlen($digits) < 8) {
                    return $m[0];
                }

                return $this->maskKeepEdges($digits, 3, 4);
            },
            $masked,
        ) ?? $masked;

        return $masked;
    }

    /**
     * Preview curto para steps de dry-run (sempre mascarado nas bordas se longo).
     */
    public function preview(string $text, int $keepStart = 2, int $keepEnd = 2): string
    {
        $masked = $this->mask($text);
        if (mb_strlen($masked) <= $keepStart + $keepEnd + 1) {
            return $this->maskKeepEdges($masked, min(1, mb_strlen($masked)), 0);
        }

        return $this->maskKeepEdges($masked, $keepStart, $keepEnd);
    }

    private function maskKeepEdges(string $value, int $keepStart, int $keepEnd): string
    {
        $len = mb_strlen($value);
        if ($len === 0) {
            return '';
        }
        if ($len <= max(1, $keepStart + $keepEnd)) {
            return str_repeat('•', max(1, $len));
        }

        $start = mb_substr($value, 0, $keepStart);
        $end = $keepEnd > 0 ? mb_substr($value, -$keepEnd) : '';

        return $start.'•••••'.$end;
    }
}

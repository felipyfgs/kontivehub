<?php

namespace App\Services\Serpro;

use App\Services\Operations\OperationsMetrics;
use App\Support\LogSanitizer;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Transporte HTTP TLS≥1.2 com mTLS opcional (PFX BLOB), hostname verify, timeouts,
 * correlação e sanitização de erros. Não registra corpo sensível.
 */
/** Não final: permite stub de transporte em testes unitários do client HTTP Integra. */
class SerproHttpTransport
{
    public function __construct(
        private readonly int $timeoutSeconds = 60,
        private readonly int $connectTimeoutSeconds = 10,
        private readonly bool $verifyTls = true,
    ) {
        if (! $this->verifyTls) {
            throw new RuntimeException('Verificação TLS não pode ser desativada no transporte SERPRO.');
        }
    }

    /**
     * @param  array{pfx: string, password: string}|null  $certificate
     * @param  list<string>  $headers
     * @return array{
     *   status: int,
     *   body: string,
     *   headers: array<string, string>,
     *   retry_after: ?int,
     *   latency_ms: int
     * }
     */
    public function request(
        string $method,
        string $url,
        ?array $certificate,
        ?string $body,
        array $headers = [],
        ?string $correlationId = null,
    ): array {
        if (! extension_loaded('curl')) {
            throw new RuntimeException('Cliente HTTP SERPRO indisponível (curl).');
        }

        if ($certificate !== null) {
            if (! defined('CURLOPT_SSLCERT_BLOB')) {
                throw new RuntimeException('Cliente mTLS sem suporte a PFX em memória.');
            }
            if (($certificate['pfx'] ?? '') === '' || ! is_string($certificate['pfx'])) {
                throw new RuntimeException('Credencial mTLS inválida.');
            }
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Falha ao iniciar cURL SERPRO.');
        }

        $allHeaders = $headers;
        if ($correlationId !== null && $correlationId !== '') {
            $allHeaders[] = 'X-Correlation-Id: '.$correlationId;
        }

        $responseHeaders = [];
        $started = hrtime(true);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTPHEADER => $allHeaders,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                $len = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $len;
            },
        ];

        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }

        if ($certificate !== null) {
            $opts[CURLOPT_SSLCERTTYPE] = 'P12';
            $opts[CURLOPT_SSLCERT_BLOB] = $certificate['pfx'];
            $opts[CURLOPT_KEYPASSWD] = (string) ($certificate['password'] ?? '');
        }

        curl_setopt_array($ch, $opts);

        $responseBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);

        if ($responseBody === false || $errno !== 0) {
            throw new RuntimeException($this->sanitizeTransportError($error !== '' ? $error : 'Falha de transporte SERPRO.'));
        }

        $retryAfter = null;
        if (isset($responseHeaders['retry-after'])) {
            $raw = $responseHeaders['retry-after'];
            if (ctype_digit($raw)) {
                $retryAfter = (int) $raw;
            }
        }

        $this->emitMetrics($status, $latencyMs, $correlationId);

        return [
            'status' => $status,
            'body' => (string) $responseBody,
            'headers' => $responseHeaders,
            'retry_after' => $retryAfter,
            'latency_ms' => $latencyMs,
        ];
    }

    public function sanitizeTransportError(string $message): string
    {
        return LogSanitizer::scrubString(
            preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message
        );
    }

    private function emitMetrics(int $status, int $latencyMs, ?string $correlationId): void
    {
        $httpClass = match (true) {
            $status === 429 => '429',
            $status >= 200 && $status < 300 => '2xx',
            $status >= 400 && $status < 500 => '4xx',
            $status >= 500 => '5xx',
            default => 'other',
        };

        Log::info('serpro.http.transport', LogSanitizer::redact([
            'event' => 'serpro.http.transport',
            'http_class' => $httpClass,
            'latency_ms' => $latencyMs,
            'correlation_id' => $correlationId,
            // sem URL completa com query sensível, sem body, sem cert
        ]));

        try {
            $metrics = app(OperationsMetrics::class);
            $metrics->observeLatency('serpro.http.latency_ms', $latencyMs, [
                'channel' => 'serpro_http',
                'http_class' => $httpClass,
            ]);
            $metrics->increment('serpro.http.result', 1, [
                'channel' => 'serpro_http',
                'http_class' => $httpClass,
            ]);
            if ($status === 429) {
                $metrics->increment('serpro.http.429', 1, ['channel' => 'serpro_http']);
            }
        } catch (\Throwable) {
            // métricas nunca derrubam transporte
        }
    }
}

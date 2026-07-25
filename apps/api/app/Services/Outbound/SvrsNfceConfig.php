<?php

namespace App\Services\Outbound;

use InvalidArgumentException;
use RuntimeException;

/**
 * Configuração tipada e validada do canal SVRS NFC-e XML.
 * Não aceita override por request de API.
 */
final class SvrsNfceConfig
{
    public function retrievalEnabled(): bool
    {
        return (bool) config('sefaz.svrs_nfce_xml.retrieval_enabled', false);
    }

    public function autoQueueEnabled(): bool
    {
        return (bool) config('sefaz.svrs_nfce_xml.auto_queue_enabled', false);
    }

    public function pilotAllowlistOnly(): bool
    {
        return (bool) config('sefaz.svrs_nfce_xml.pilot_allowlist_only', false);
    }

    public function host(): string
    {
        $host = strtolower(trim((string) config('sefaz.svrs_nfce_xml.host', 'dfe-portal.svrs.rs.gov.br')));
        $this->assertAllowedHost($host);

        return $host;
    }

    /**
     * @return list<string>
     */
    public function allowedHosts(): array
    {
        $hosts = config('sefaz.svrs_nfce_xml.allowed_hosts', ['dfe-portal.svrs.rs.gov.br']);
        if (! is_array($hosts) || $hosts === []) {
            throw new RuntimeException('Lista de hosts SVRS vazia ou inválida.');
        }

        return array_values(array_map(
            static fn ($h) => strtolower(trim((string) $h)),
            $hosts
        ));
    }

    public function getUrl(): string
    {
        return $this->buildUrl($this->getPath());
    }

    public function postUrl(): string
    {
        return $this->buildUrl($this->postPath());
    }

    public function getPath(): string
    {
        return $this->normalizePath((string) config('sefaz.svrs_nfce_xml.get_path', '/NFCESSL/DownloadXMLDFe'));
    }

    public function postPath(): string
    {
        return $this->normalizePath((string) config('sefaz.svrs_nfce_xml.post_path', '/NfceSSL/DownloadXmlDfe'));
    }

    public function timeoutSeconds(): int
    {
        return max(1, (int) config('sefaz.svrs_nfce_xml.timeout_seconds', 30));
    }

    public function connectTimeoutSeconds(): int
    {
        return max(1, (int) config('sefaz.svrs_nfce_xml.connect_timeout_seconds', 10));
    }

    public function maxHtmlBytes(): int
    {
        return max(64, (int) config('sefaz.svrs_nfce_xml.max_html_bytes', 524288));
    }

    public function maxLiteralBytes(): int
    {
        return max(64, (int) config('sefaz.svrs_nfce_xml.max_literal_bytes', 262144));
    }

    public function maxXmlBytes(): int
    {
        return max(64, (int) config('sefaz.svrs_nfce_xml.max_xml_bytes', 262144));
    }

    public function maxInflightGlobal(): int
    {
        return max(1, (int) config('sefaz.svrs_nfce_xml.max_inflight_global', 1));
    }

    public function minIntervalGlobalSeconds(): float
    {
        return max(0.0, (float) config('sefaz.svrs_nfce_xml.min_interval_global_seconds', 5));
    }

    public function minIntervalRootSeconds(): float
    {
        return max(0.0, (float) config('sefaz.svrs_nfce_xml.min_interval_root_seconds', 30));
    }

    public function maxKeysPerRun(): int
    {
        return max(1, (int) config('sefaz.svrs_nfce_xml.max_keys_per_run', 20));
    }

    public function maxRecoverableAttempts(): int
    {
        return max(1, (int) config('sefaz.svrs_nfce_xml.max_recoverable_attempts', 5));
    }

    /**
     * @return list<int>
     */
    public function retryBackoffSeconds(): array
    {
        $raw = config('sefaz.svrs_nfce_xml.retry_backoff_seconds', [900, 3600, 21600, 43200]);
        if (! is_array($raw) || $raw === []) {
            return [900, 3600, 21600, 43200];
        }

        return array_values(array_map(static fn ($v) => max(1, (int) $v), $raw));
    }

    public function retryJitterRatio(): float
    {
        return max(0.0, min(0.5, (float) config('sefaz.svrs_nfce_xml.retry_jitter_ratio', 0.1)));
    }

    public function breakerOpenSeconds(): int
    {
        return max(60, (int) config('sefaz.svrs_nfce_xml.breaker_open_seconds', 3600));
    }

    public function breakerFailureThreshold(): int
    {
        return max(1, (int) config('sefaz.svrs_nfce_xml.breaker_failure_threshold', 3));
    }

    public function queue(): string
    {
        return (string) config('sefaz.svrs_nfce_xml.queue', 'capture-outbound-ma');
    }

    public function lockTtlSeconds(): int
    {
        return max(30, (int) config('sefaz.svrs_nfce_xml.lock_ttl_seconds', 180));
    }

    public function parserVersion(): string
    {
        return (string) config('sefaz.svrs_nfce_xml.wrapper_parser_version', '1');
    }

    /**
     * @return array{sistema: string, OrigemSite: string}
     */
    public function postStaticFields(): array
    {
        $fields = config('sefaz.svrs_nfce_xml.post_fields', [
            'sistema' => 'Nfce',
            'OrigemSite' => '0',
        ]);

        return [
            'sistema' => (string) ($fields['sistema'] ?? 'Nfce'),
            'OrigemSite' => (string) ($fields['OrigemSite'] ?? '0'),
        ];
    }

    public function isHostAllowed(string $host): bool
    {
        $host = strtolower(trim($host));

        return in_array($host, $this->allowedHosts(), true);
    }

    public function assertAllowedHost(string $host): void
    {
        if (! $this->isHostAllowed($host)) {
            throw new InvalidArgumentException('Host SVRS não allowlisted.');
        }
    }

    public function assertUrlAllowed(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw new InvalidArgumentException('URL SVRS inválida.');
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw new InvalidArgumentException('Somente HTTPS é permitido no canal SVRS.');
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        $this->assertAllowedHost($host);
    }

    private function buildUrl(string $path): string
    {
        $url = 'https://'.$this->host().$path;
        $this->assertUrlAllowed($url);

        return $url;
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === '' || $path[0] !== '/') {
            throw new InvalidArgumentException('Path SVRS deve começar com /.');
        }
        if (str_contains($path, '://') || str_contains($path, '?') || str_contains($path, '#')) {
            throw new InvalidArgumentException('Path SVRS inválido.');
        }

        return $path;
    }
}

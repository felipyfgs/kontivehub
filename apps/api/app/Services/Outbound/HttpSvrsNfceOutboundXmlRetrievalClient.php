<?php

namespace App\Services\Outbound;

use App\Contracts\SvrsNfceDownloadResponseParser;
use App\Contracts\SvrsNfceOutboundXmlRetrievalClient;
use App\DTO\Outbound\SvrsNfceRetrievalRequest;
use App\DTO\Outbound\SvrsNfceRetrievalResult;
use App\Enums\SvrsNfceTransportOutcome;
use Throwable;

/**
 * Transporte HTTP+mTLS do portal SVRS DownloadXMLDFe.
 * PFX por BLOB em memória; cookie engine só durante a recuperação; limpeza garantida.
 */
final class HttpSvrsNfceOutboundXmlRetrievalClient implements SvrsNfceOutboundXmlRetrievalClient
{
    public function __construct(
        private readonly SvrsNfceConfig $config,
        private readonly SvrsNfceDownloadResponseParser $parser,
        private readonly SvrsNfceKillSwitchService $killSwitch,
    ) {}

    public function isAvailable(): bool
    {
        return $this->config->retrievalEnabled() && ! $this->killSwitch->isActive();
    }

    public function retrieve(SvrsNfceRetrievalRequest $request, array $certificate): SvrsNfceRetrievalResult
    {
        $started = hrtime(true);

        if (! $this->config->retrievalEnabled()) {
            return $this->done(SvrsNfceTransportOutcome::ChannelDisabled, $started, detail: 'Canal desabilitado.');
        }
        if ($this->killSwitch->isActive()) {
            return $this->done(SvrsNfceTransportOutcome::KillSwitch, $started, detail: 'Kill switch ativo.');
        }

        if (($certificate['pfx'] ?? '') === '' || ! is_string($certificate['pfx'])) {
            return $this->done(SvrsNfceTransportOutcome::AuthForbidden, $started, detail: 'Credencial mTLS inválida.');
        }

        $cookieFile = null;
        $ch = null;

        try {
            $this->config->assertUrlAllowed($this->config->getUrl());
            $this->config->assertUrlAllowed($this->config->postUrl());

            $cookieFile = $this->tempCookieJar();
            $ch = $this->initHandle($certificate, $cookieFile);

            // GET formulário
            $getStarted = hrtime(true);
            $get = $this->perform($ch, 'GET', $this->config->getUrl(), null, [
                'User-Agent: Mozilla/5.0 (compatible; NfseAdnCapture/1.0; internal-accounting-office)',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9',
            ]);
            $getMs = (int) ((hrtime(true) - $getStarted) / 1_000_000);

            $mapped = $this->mapHttp($get);
            if ($mapped !== null) {
                return $this->done($mapped['outcome'], $started, $get['status'], $mapped['retry_after'], getMs: $getMs, detail: $mapped['detail'], headers: $get['headers']);
            }

            if (strlen($get['body']) > $this->config->maxHtmlBytes()) {
                return $this->done(SvrsNfceTransportOutcome::PayloadTooLarge, $started, $get['status'], getMs: $getMs, detail: 'HTML GET excede limite.');
            }

            $formParse = $this->parser->parseFormPage($get['body']);
            // Limpar body GET da memória de trabalho o quanto antes
            unset($get);

            if ($formParse->outcome !== SvrsNfceTransportOutcome::FormOk) {
                return $this->done($formParse->outcome, $started, getMs: $getMs, detail: $formParse->sanitizedDetail, parserVersion: $formParse->parserVersion);
            }

            // POST form-urlencoded
            $fields = array_merge($this->config->postStaticFields(), [
                'Ambiente' => $request->portalAmbiente(),
                'ChaveAcessoDfe' => $request->accessKey,
            ]);
            $body = http_build_query($fields);

            $postStarted = hrtime(true);
            $post = $this->perform($ch, 'POST', $this->config->postUrl(), $body, [
                'User-Agent: Mozilla/5.0 (compatible; NfseAdnCapture/1.0; internal-accounting-office)',
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9',
                'Origin: https://'.$this->config->host(),
                'Referer: '.$this->config->getUrl(),
            ]);
            $postMs = (int) ((hrtime(true) - $postStarted) / 1_000_000);

            $mappedPost = $this->mapHttp($post);
            if ($mappedPost !== null) {
                return $this->done($mappedPost['outcome'], $started, $post['status'], $mappedPost['retry_after'], getMs: $getMs, postMs: $postMs, detail: $mappedPost['detail'], headers: $post['headers'], parserVersion: $formParse->parserVersion);
            }

            if (strlen($post['body']) > $this->config->maxHtmlBytes()) {
                return $this->done(SvrsNfceTransportOutcome::PayloadTooLarge, $started, $post['status'], getMs: $getMs, postMs: $postMs, detail: 'HTML POST excede limite.');
            }

            $parse = $this->parser->parseDownloadPage($post['body']);
            $httpStatus = $post['status'];
            // Não propagar HTML
            unset($post);

            if (! $parse->isSuccess()) {
                return $this->done($parse->outcome, $started, $httpStatus, getMs: $getMs, postMs: $postMs, detail: $parse->sanitizedDetail, parserVersion: $parse->parserVersion);
            }

            $xml = $parse->xmlBytes ?? '';
            $sha = hash('sha256', $xml);

            return new SvrsNfceRetrievalResult(
                outcome: SvrsNfceTransportOutcome::Captured,
                xmlBytes: $xml,
                sha256: $sha,
                httpStatus: $httpStatus,
                parserVersion: $parse->parserVersion,
                getLatencyMs: $getMs,
                postLatencyMs: $postMs,
                totalLatencyMs: (int) ((hrtime(true) - $started) / 1_000_000),
            );
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            $outcome = str_contains(strtolower($msg), 'host') || str_contains(strtolower($msg), 'tls') || str_contains(strtolower($msg), 'ssl')
                ? SvrsNfceTransportOutcome::TlsOrHostRejected
                : SvrsNfceTransportOutcome::NetworkError;

            return $this->done($outcome, $started, detail: 'Falha de transporte (sanitizada).');
        } finally {
            if (is_resource($ch) || $ch instanceof \CurlHandle) {
                curl_close($ch);
            }
            $ch = null;
            // Limpar referências de certificado no caller; cookie jar efêmero
            if (is_string($cookieFile) && is_file($cookieFile)) {
                @unlink($cookieFile);
            }
            $certificate = [];
            unset($certificate);
        }
    }

    /**
     * @param  array{pfx: string, password: string}  $certificate
     * @return \CurlHandle|resource
     */
    private function initHandle(array $certificate, string $cookieFile)
    {
        if (! extension_loaded('curl')) {
            throw new \RuntimeException('ext-curl indisponível.');
        }
        if (! defined('CURLOPT_SSLCERT_BLOB')) {
            throw new \RuntimeException('curl sem suporte a PFX BLOB.');
        }

        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('Falha ao iniciar curl.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->config->timeoutSeconds(),
            CURLOPT_CONNECTTIMEOUT => $this->config->connectTimeoutSeconds(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_SSLCERTTYPE => 'P12',
            CURLOPT_SSLCERT_BLOB => $certificate['pfx'],
            CURLOPT_KEYPASSWD => (string) ($certificate['password'] ?? ''),
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_HEADER => true,
        ]);

        $ca = config('sefaz.ca_bundle');
        if (is_string($ca) && $ca !== '' && is_file($ca)) {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }

        return $ch;
    }

    /**
     * @param  \CurlHandle|resource  $ch
     * @param  list<string>  $headers
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function perform($ch, string $method, string $url, ?string $body, array $headers): array
    {
        $this->config->assertUrlAllowed($url);

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?? '');
        } else {
            curl_setopt($ch, CURLOPT_POST, false);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, null);
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            // Não incluir URL com query sensível
            throw new \RuntimeException('curl error: '.mb_substr($err, 0, 120));
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headerBlob = substr((string) $raw, 0, $headerSize);
        $responseBody = substr((string) $raw, $headerSize);

        // Redirect?
        if ($status >= 300 && $status < 400) {
            $location = $this->headerValue($headerBlob, 'location');
            if ($location !== null) {
                $abs = $this->resolveLocation($url, $location);
                try {
                    $this->config->assertUrlAllowed($abs);
                } catch (Throwable) {
                    throw new \RuntimeException('redirect rejected');
                }
            }
            throw new \RuntimeException('redirect not followed');
        }

        return [
            'status' => $status,
            'body' => $responseBody,
            'headers' => $this->parseHeaders($headerBlob),
        ];
    }

    /**
     * @param  array{status: int, body: string, headers: array<string, string>}  $response
     * @return array{outcome: SvrsNfceTransportOutcome, retry_after: ?int, detail: string}|null
     */
    private function mapHttp(array $response): ?array
    {
        $status = $response['status'];
        if ($status === 200) {
            return null;
        }
        if ($status === 401 || $status === 403) {
            return ['outcome' => SvrsNfceTransportOutcome::AuthForbidden, 'retry_after' => null, 'detail' => 'HTTP '.$status];
        }
        if ($status === 429) {
            return [
                'outcome' => SvrsNfceTransportOutcome::RateLimited,
                'retry_after' => $this->parseRetryAfter($response['headers']),
                'detail' => 'HTTP 429',
            ];
        }
        if ($status === 503 || $status === 502 || $status === 504) {
            return [
                'outcome' => SvrsNfceTransportOutcome::HttpTransient,
                'retry_after' => $this->parseRetryAfter($response['headers']),
                'detail' => 'HTTP '.$status,
            ];
        }
        if ($status >= 500) {
            return ['outcome' => SvrsNfceTransportOutcome::HttpTransient, 'retry_after' => null, 'detail' => 'HTTP '.$status];
        }
        if ($status >= 400) {
            return ['outcome' => SvrsNfceTransportOutcome::HttpTransient, 'retry_after' => null, 'detail' => 'HTTP '.$status];
        }

        return ['outcome' => SvrsNfceTransportOutcome::NetworkError, 'retry_after' => null, 'detail' => 'HTTP '.$status];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function parseRetryAfter(array $headers): ?int
    {
        $raw = $headers['retry-after'] ?? null;
        if ($raw === null || ! ctype_digit($raw)) {
            return null;
        }
        $sec = (int) $raw;
        if ($sec < 1 || $sec > 86400) {
            return null;
        }

        return $sec;
    }

    private function parseHeaders(string $blob): array
    {
        $out = [];
        foreach (preg_split("/\r\n|\n|\r/", $blob) ?: [] as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $out[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        return $out;
    }

    private function headerValue(string $blob, string $name): ?string
    {
        $headers = $this->parseHeaders($blob);

        return $headers[strtolower($name)] ?? null;
    }

    private function resolveLocation(string $base, string $location): string
    {
        if (str_starts_with($location, 'https://')) {
            return $location;
        }
        if (str_starts_with($location, 'http://')) {
            return $location; // será rejeitado por assertUrlAllowed
        }
        $parts = parse_url($base);
        $host = $parts['host'] ?? '';
        $path = str_starts_with($location, '/') ? $location : rtrim(dirname($parts['path'] ?? '/'), '/').'/'.$location;

        return 'https://'.$host.$path;
    }

    private function tempCookieJar(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'svrsck');
        if ($path === false) {
            throw new \RuntimeException('cookie jar');
        }

        return $path;
    }

    private function done(
        SvrsNfceTransportOutcome $outcome,
        int $started,
        ?int $httpStatus = null,
        ?int $retryAfter = null,
        ?int $getMs = null,
        ?int $postMs = null,
        ?string $detail = null,
        ?string $parserVersion = null,
        array $headers = [],
    ): SvrsNfceRetrievalResult {
        return new SvrsNfceRetrievalResult(
            outcome: $outcome,
            httpStatus: $httpStatus,
            retryAfterSeconds: $retryAfter,
            parserVersion: $parserVersion,
            getLatencyMs: $getMs,
            postLatencyMs: $postMs,
            totalLatencyMs: (int) ((hrtime(true) - $started) / 1_000_000),
            sanitizedDetail: $detail,
            responseHeaders: array_intersect_key($headers, array_flip(['retry-after', 'content-type'])),
        );
    }
}

<?php

namespace App\Services\Adn;

use App\Exceptions\Adn\AdnPermanentException;
use App\Exceptions\Adn\AdnRetryableException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Transporte cURL mTLS: PFX apenas em memória (BLOB), TLS ≥ 1.2, hostname verificado.
 */
class CurlMtlsTransport
{
    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly int $connectTimeoutSeconds = 10,
        private readonly bool $verifyTls = true,
    ) {
        if (! $this->verifyTls) {
            throw new RuntimeException('Verificação TLS não pode ser desativada.');
        }
    }

    /**
     * @param  array{pfx: string, password: string}  $certificate
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function get(string $url, array $certificate): array
    {
        return $this->request('GET', $url, $certificate, null, [
            'Accept: application/json, application/xml, text/xml, */*',
        ]);
    }

    /**
     * POST SOAP/mTLS com PFX em memória (DistDFe SEFAZ e similares).
     *
     * @param  array{pfx: string, password: string}  $certificate
     * @param  list<string>  $extraHeaders
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public function post(string $url, array $certificate, string $body, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'Content-Type: application/soap+xml; charset=utf-8',
            'Accept: application/soap+xml, application/xml, text/xml, */*',
        ], $extraHeaders);

        return $this->request('POST', $url, $certificate, $body, $headers);
    }

    /**
     * @param  array{pfx: string, password: string}  $certificate
     * @param  list<string>  $httpHeaders
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    private function request(
        string $method,
        string $url,
        array $certificate,
        ?string $body,
        array $httpHeaders,
    ): array {
        if (! extension_loaded('curl')) {
            throw new AdnPermanentException('Cliente mTLS indisponível por falha de configuração.');
        }

        if (! defined('CURLOPT_SSLCERT_BLOB')) {
            throw new AdnPermanentException('Cliente mTLS sem suporte a PFX em memória.');
        }

        if (($certificate['pfx'] ?? '') === '' || ! is_string($certificate['pfx'])) {
            throw new AdnPermanentException('Credencial mTLS inválida.');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new AdnPermanentException('Cliente mTLS indisponível por falha de configuração.');
        }

        $responseHeaders = [];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_SSLCERTTYPE => 'P12',
            CURLOPT_SSLCERT_BLOB => $certificate['pfx'],
            CURLOPT_KEYPASSWD => (string) ($certificate['password'] ?? ''),
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
                $len = strlen($line);
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }

                return $len;
            },
        ];

        // Bundle extra (ICP-Brasil / intermediários SEFAZ) quando o SO não tem a cadeia completa.
        $caBundle = (string) config('sefaz.ca_bundle', '');
        if ($caBundle !== '' && is_readable($caBundle)) {
            $opts[CURLOPT_CAINFO] = $caBundle;
        }

        if (strtoupper($method) === 'POST') {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body ?? '';
        }

        curl_setopt_array($ch, $opts);

        $responseBody = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            $detail = $error !== '' ? $error : ('curl_errno='.$errno);
            // Log sanitizado: sem PFX/senha; útil para diagnosticar CA/TLS.
            Log::warning('mtls.transport_failed', [
                'errno' => $errno,
                'error' => $detail,
                'url_host' => parse_url($url, PHP_URL_HOST),
            ]);
            unset($error);

            if ($this->isRetryableCurlError($errno)) {
                throw new AdnRetryableException('Falha temporária na comunicação mTLS: '.$detail);
            }

            throw new AdnPermanentException('Falha permanente na comunicação mTLS: '.$detail);
        }

        if ($responseBody === false) {
            throw new AdnRetryableException('Servidor remoto não retornou uma resposta utilizável.');
        }

        return [
            'status' => $status,
            'body' => $responseBody,
            'headers' => $responseHeaders,
        ];
    }

    private function isRetryableCurlError(int $errno): bool
    {
        return in_array($errno, [
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_CONNECT,
            CURLE_OPERATION_TIMEDOUT,
            CURLE_PARTIAL_FILE,
            CURLE_GOT_NOTHING,
            CURLE_SEND_ERROR,
            CURLE_RECV_ERROR,
        ], true);
    }
}

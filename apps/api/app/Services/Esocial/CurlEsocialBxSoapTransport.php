<?php

namespace App\Services\Esocial;

use App\Contracts\EsocialBxCurlRuntime;
use App\Contracts\EsocialBxSoapTransport;
use App\DTO\Esocial\EsocialBxHttpResponse;
use App\Exceptions\EsocialBxException;

final class CurlEsocialBxSoapTransport implements EsocialBxSoapTransport
{
    public function __construct(
        private readonly EsocialBxCurlRuntime $runtime,
        private readonly EsocialBxConfig $config,
    ) {}

    public function post(
        string $endpoint,
        string $soapAction,
        string $envelope,
        string $pfxBinary,
        string $password,
    ): EsocialBxHttpResponse {
        if (! defined('CURLOPT_SSLCERT_BLOB')) {
            throw new EsocialBxException(
                'ESOCIAL_BX_CURL_CERT_BLOB_UNAVAILABLE',
                'Runtime cURL não suporta certificado em memória.',
                blocked: true,
            );
        }
        $this->config->assertAllowedEndpoint($endpoint);
        if ($soapAction === '' || strlen($soapAction) > 512 || preg_match('/[\r\n"]/', $soapAction) === 1) {
            throw new EsocialBxException(
                'ESOCIAL_BX_SOAP_ACTION_INVALID',
                'SOAPAction eSocial BX inválido.',
                blocked: true,
            );
        }
        if ($envelope === '' || strlen($envelope) > 5 * 1024 * 1024) {
            throw new EsocialBxException(
                'ESOCIAL_BX_ENVELOPE_INVALID',
                'Envelope SOAP eSocial BX inválido.',
                blocked: true,
            );
        }
        if ($pfxBinary === '' || strlen($pfxBinary) > 1024 * 1024 || strlen($password) > 4096) {
            throw new EsocialBxException(
                'ESOCIAL_BX_CREDENTIAL_INVALID',
                'Credencial mTLS eSocial BX inválida.',
                blocked: true,
            );
        }

        return $this->runtime->execute($endpoint, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $envelope,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT => (int) config('fgts_esocial.official_bx.connect_timeout_seconds', 15),
            CURLOPT_TIMEOUT => (int) config('fgts_esocial.official_bx.timeout_seconds', 90),
            CURLOPT_USERAGENT => (string) config('fgts_esocial.official_bx.user_agent'),
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=UTF-8',
                'SOAPAction: "'.$soapAction.'"',
                'Content-Length: '.strlen($envelope),
            ],
            CURLOPT_SSLCERT_BLOB => $pfxBinary,
            CURLOPT_SSLCERTTYPE => 'P12',
            CURLOPT_KEYPASSWD => $password,
        ]);
    }
}

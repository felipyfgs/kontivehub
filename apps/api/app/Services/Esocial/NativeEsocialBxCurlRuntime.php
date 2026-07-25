<?php

declare(strict_types=1);

namespace App\Services\Esocial;

use App\Contracts\EsocialBxCurlRuntime;
use App\DTO\Esocial\EsocialBxHttpResponse;
use App\Exceptions\EsocialBxException;

final class NativeEsocialBxCurlRuntime implements EsocialBxCurlRuntime
{
    public function execute(string $endpoint, array $options): EsocialBxHttpResponse
    {
        $handle = curl_init($endpoint);
        if ($handle === false) {
            throw new EsocialBxException(
                'ESOCIAL_BX_TRANSPORT_INIT_FAILED',
                'Falha ao iniciar transporte eSocial BX.',
                blocked: true,
            );
        }

        $headers = [];
        $options[CURLOPT_HEADERFUNCTION] = static function ($curl, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        };

        try {
            if (! curl_setopt_array($handle, $options)) {
                throw new EsocialBxException(
                    'ESOCIAL_BX_TRANSPORT_CONFIG_FAILED',
                    'Falha ao configurar transporte eSocial BX.',
                    blocked: true,
                );
            }

            $body = curl_exec($handle);
            if ($body === false) {
                $errno = curl_errno($handle);
                throw new EsocialBxException(
                    'ESOCIAL_BX_TRANSPORT_FAILED',
                    'Falha de rede/TLS ao acessar eSocial BX.',
                    retryable: in_array($errno, [
                        CURLE_COULDNT_RESOLVE_HOST,
                        CURLE_COULDNT_CONNECT,
                        CURLE_OPERATION_TIMEDOUT,
                        CURLE_PARTIAL_FILE,
                        CURLE_GOT_NOTHING,
                        CURLE_RECV_ERROR,
                        CURLE_SEND_ERROR,
                    ], true),
                );
            }

            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($status < 100 || $status > 599) {
                throw new EsocialBxException(
                    'ESOCIAL_BX_TRANSPORT_FAILED',
                    'Resposta HTTP inválida do eSocial BX.',
                    retryable: true,
                );
            }

            return new EsocialBxHttpResponse(
                status: $status,
                body: (string) $body,
                contentType: (string) ($headers['content-type'] ?? 'text/xml'),
            );
        } finally {
            curl_close($handle);
        }
    }
}

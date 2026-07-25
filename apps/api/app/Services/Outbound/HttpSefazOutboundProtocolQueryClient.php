<?php

namespace App\Services\Outbound;

use App\Contracts\SefazOutboundProtocolQueryClient;
use App\DTO\Outbound\ProtocolQueryResult;
use App\Services\Adn\CurlMtlsTransport;
use RuntimeException;
use Throwable;

/**
 * Transporte SOAP/mTLS próprio para NFeConsultaProtocolo (SVAN/55, SVRS/65).
 */
final class HttpSefazOutboundProtocolQueryClient implements SefazOutboundProtocolQueryClient
{
    public function __construct(
        private readonly CurlMtlsTransport $transport,
        private readonly ProtocolQueryResponseParser $parser,
    ) {}

    public function consult(
        string $accessKey,
        string $model,
        string $environment,
        array $certificate,
    ): ProtocolQueryResult {
        $cfg = config("sefaz.ma_outbound.consulta_protocolo.{$model}");
        if (! is_array($cfg)) {
            throw new RuntimeException("Endpoint de consulta não configurado para modelo {$model}.");
        }

        $envKey = $environment === 'homologation' ? 'homologation' : 'production';
        $url = (string) ($cfg[$envKey] ?? '');
        if ($url === '') {
            throw new RuntimeException('URL de consulta de protocolo vazia.');
        }

        $tpAmb = $environment === 'homologation' ? '2' : '1';
        $key = strtoupper(preg_replace('/\s+/', '', $accessKey) ?? $accessKey);
        $body = $this->buildSoap($key, $tpAmb, (string) ($cfg['namespace'] ?? ''));

        try {
            $response = $this->transport->post($url, $certificate, $body, [
                'SOAPAction: "'.($cfg['soap_action'] ?? '').'"',
            ]);
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            // Timeout/rede → ambíguo (retry). CA/TLS/config → mensagem clara.
            $ambiguous = str_contains(strtolower($msg), 'tempor')
                || str_contains(strtolower($msg), 'timeout');

            return new ProtocolQueryResult(
                cStat: '000',
                xMotivo: mb_substr($msg !== '' ? $msg : 'Falha de transporte na consulta.', 0, 500),
                consultedAccessKey: $key,
                ambiguousTimeout: $ambiguous,
                sanitized: [
                    'transport_error' => true,
                    'exception' => class_basename($e),
                ],
            );
        }

        return $this->parser->parse($response['body'] ?? '', $key);
    }

    private function buildSoap(string $accessKey, string $tpAmb, string $ns): string
    {
        $ns = $ns !== '' ? $ns : 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeConsultaProtocolo4';

        // SVRS/SVAN: nfeDadosMsg direto no Body (sem wrapper nfeConsultaNF) e payload sem escape.
        $dados = <<<XML
<consSitNFe xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><tpAmb>{$tpAmb}</tpAmb><xServ>CONSULTAR</xServ><chNFe>{$accessKey}</chNFe></consSitNFe>
XML;

        return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
  <soap12:Body>
    <nfeDadosMsg xmlns="{$ns}">{$dados}</nfeDadosMsg>
  </soap12:Body>
</soap12:Envelope>
XML;
    }
}

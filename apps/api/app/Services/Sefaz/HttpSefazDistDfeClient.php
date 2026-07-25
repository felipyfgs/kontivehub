<?php

namespace App\Services\Sefaz;

use App\Contracts\SefazDistDfeClient;
use App\Domain\Sefaz\DistDfePageDto;
use App\Exceptions\Adn\AdnPermanentException;
use App\Exceptions\Adn\AdnRetryableException;
use App\Services\Adn\CurlMtlsTransport;

/**
 * Cliente DistDFe próprio — SOAP 1.2 + mTLS PFX BLOB (sem lib comunitária de emissão em runtime).
 */
final class HttpSefazDistDfeClient implements SefazDistDfeClient
{
    public function __construct(
        private readonly CurlMtlsTransport $transport,
        private readonly DistDfeResponseParser $parser,
    ) {}

    public function distByNsu(
        array $certificate,
        string $cnpjConsulta,
        int $ultNsu,
        string $cUfAutor,
    ): DistDfePageDto {
        $cnpj = $this->normalizeCnpj($cnpjConsulta);
        $nsu = str_pad((string) max(0, $ultNsu), 15, '0', STR_PAD_LEFT);
        $inner = $this->distDfeIntShell($cnpj, $cUfAutor, "<distNSU><ultNSU>{$nsu}</ultNSU></distNSU>");

        return $this->call($certificate, $inner);
    }

    public function distByAccessKey(
        array $certificate,
        string $cnpjConsulta,
        string $accessKey,
        string $cUfAutor,
    ): DistDfePageDto {
        $cnpj = $this->normalizeCnpj($cnpjConsulta);
        $chave = strtoupper(preg_replace('/\s+/', '', $accessKey) ?? $accessKey);
        $inner = $this->distDfeIntShell($cnpj, $cUfAutor, "<consChNFe><chNFe>{$chave}</chNFe></consChNFe>");

        return $this->call($certificate, $inner);
    }

    /**
     * @param  array{pfx: string, password: string}  $certificate
     */
    private function call(array $certificate, string $distDfeIntXml): DistDfePageDto
    {
        $url = $this->endpointUrl();
        $ns = (string) config('sefaz.nfe.namespace');
        $envelope = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
  <soap12:Body>
    <nfeDistDFeInteresse xmlns="{$ns}">
      <nfeDadosMsg>{$distDfeIntXml}</nfeDadosMsg>
    </nfeDistDFeInteresse>
  </soap12:Body>
</soap12:Envelope>
XML;

        $response = $this->transport->post($url, $certificate, $envelope, [
            'SOAPAction: "'.(string) config('sefaz.nfe.soap_action').'"',
        ]);

        $status = $response['status'];
        if ($status >= 500) {
            throw new AdnRetryableException('SEFAZ DistDFe temporariamente indisponível.');
        }
        if ($status >= 400) {
            throw new AdnPermanentException('SEFAZ DistDFe rejeitou a requisição HTTP.');
        }

        return $this->parser->parse($response['body']);
    }

    private function distDfeIntShell(string $cnpj, string $cUfAutor, string $choiceXml): string
    {
        $versao = (string) config('sefaz.nfe.layout_version', '1.01');
        $tpAmb = $this->tpAmb();
        $uf = preg_replace('/\D/', '', $cUfAutor) ?: '91';
        $uf = str_pad(substr($uf, 0, 2), 2, '0', STR_PAD_LEFT);

        return '<distDFeInt xmlns="http://www.portalfiscal.inf.br/nfe" versao="'.$versao.'">'
            .'<tpAmb>'.$tpAmb.'</tpAmb>'
            .'<cUFAutor>'.$uf.'</cUFAutor>'
            .'<CNPJ>'.$cnpj.'</CNPJ>'
            .$choiceXml
            .'</distDFeInt>';
    }

    private function endpointUrl(): string
    {
        $env = (string) config('sefaz.environment', 'production');
        $key = $env === 'homologation' ? 'homologation' : 'production';

        return (string) config('sefaz.nfe.'.$key);
    }

    private function tpAmb(): string
    {
        return (string) config('sefaz.environment', 'production') === 'homologation' ? '2' : '1';
    }

    private function normalizeCnpj(string $cnpj): string
    {
        $c = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $cnpj) ?? $cnpj);
        if (strlen($c) !== 14) {
            throw new AdnPermanentException('CNPJ de consulta DistDFe inválido.');
        }

        return $c;
    }
}

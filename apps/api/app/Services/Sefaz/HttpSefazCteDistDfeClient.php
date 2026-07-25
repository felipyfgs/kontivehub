<?php

namespace App\Services\Sefaz;

use App\Contracts\SefazCteDistDfeClient;
use App\Domain\Sefaz\DistDfePageDto;
use App\Exceptions\Adn\AdnPermanentException;
use App\Exceptions\Adn\AdnRetryableException;
use App\Services\Adn\CurlMtlsTransport;

/**
 * Cliente CT-e DistDFe — SOAP 1.2 + mTLS PFX BLOB (sem lib comunitária em runtime).
 *
 * distNSU = fluxo; consNSU = reparo de NSU conhecido.
 */
final class HttpSefazCteDistDfeClient implements SefazCteDistDfeClient
{
    public function __construct(
        private readonly CurlMtlsTransport $transport,
        private readonly DistDfeResponseParser $parser,
    ) {}

    public function distByLastNsu(
        array $certificate,
        string $cnpjConsulta,
        int $ultNsu,
        string $cUfAutor,
    ): DistDfePageDto {
        $cnpj = $this->normalizeCnpj($cnpjConsulta);
        $nsu = $this->formatNsu($ultNsu);
        $inner = $this->distDfeIntShell($cnpj, $cUfAutor, "<distNSU><ultNSU>{$nsu}</ultNSU></distNSU>");

        return $this->call($certificate, $inner);
    }

    public function distByNsu(
        array $certificate,
        string $cnpjConsulta,
        int $ultNsu,
        string $cUfAutor,
    ): DistDfePageDto {
        return $this->distByLastNsu($certificate, $cnpjConsulta, $ultNsu, $cUfAutor);
    }

    public function findByNsu(
        array $certificate,
        string $cnpjConsulta,
        int $nsu,
        string $cUfAutor,
    ): DistDfePageDto {
        if ($nsu < 1) {
            throw new AdnPermanentException('consNSU exige NSU conhecido e positivo.');
        }

        $cnpj = $this->normalizeCnpj($cnpjConsulta);
        $nsuStr = $this->formatNsu($nsu);
        $inner = $this->distDfeIntShell($cnpj, $cUfAutor, "<consNSU><NSU>{$nsuStr}</NSU></consNSU>");

        return $this->call($certificate, $inner);
    }

    /**
     * @param  array{pfx: string, password: string}  $certificate
     */
    private function call(array $certificate, string $distDfeIntXml): DistDfePageDto
    {
        $this->assertCertificateMaterial($certificate);

        $url = $this->endpointUrl();
        $ns = (string) config('sefaz.cte.namespace', 'http://www.portalfiscal.inf.br/cte/wsdl/CTeDistribuicaoDFe');
        $envelope = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
  <soap12:Body>
    <cteDistDFeInteresse xmlns="{$ns}">
      <cteDadosMsg>{$distDfeIntXml}</cteDadosMsg>
    </cteDistDFeInteresse>
  </soap12:Body>
</soap12:Envelope>
XML;

        try {
            $response = $this->transport->post($url, $certificate, $envelope, [
                'SOAPAction: "'.(string) config('sefaz.cte.soap_action', $ns.'/cteDistDFeInteresse').'"',
            ]);
        } catch (\Throwable $e) {
            // Nunca vazar PEM/PFX/senha em mensagem
            $msg = $e->getMessage();
            if ($this->looksSensitive($msg)) {
                throw new AdnRetryableException('Falha de transporte TLS/mTLS no CT-e DistDFe.');
            }
            throw $e;
        }

        $status = $response['status'];
        if ($status >= 500) {
            throw new AdnRetryableException('SEFAZ CT-e DistDFe temporariamente indisponível.');
        }
        if ($status >= 400) {
            throw new AdnPermanentException('SEFAZ CT-e DistDFe rejeitou a requisição HTTP.');
        }

        return $this->parser->parse($response['body']);
    }

    private function distDfeIntShell(string $cnpj, string $cUfAutor, string $choiceXml): string
    {
        $versao = (string) config('sefaz.cte.layout_version', '1.00');
        $tpAmb = (string) config('sefaz.environment', 'production') === 'homologation' ? '2' : '1';
        $uf = preg_replace('/\D/', '', $cUfAutor) ?: '91';
        $uf = str_pad(substr($uf, 0, 2), 2, '0', STR_PAD_LEFT);

        return '<distDFeInt xmlns="http://www.portalfiscal.inf.br/cte" versao="'.$versao.'">'
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

        return (string) config('sefaz.cte.'.$key);
    }

    private function normalizeCnpj(string $cnpj): string
    {
        $c = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $cnpj) ?? $cnpj);
        if (strlen($c) !== 14) {
            throw new AdnPermanentException('CNPJ de consulta CT-e DistDFe inválido.');
        }

        return $c;
    }

    private function formatNsu(int $nsu): string
    {
        return str_pad((string) max(0, $nsu), 15, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array{pfx?: string, password?: string}  $certificate
     */
    private function assertCertificateMaterial(array $certificate): void
    {
        if (! isset($certificate['pfx'], $certificate['password'])) {
            throw new AdnPermanentException('Certificado A1 ausente para CT-e DistDFe.');
        }
        if ($certificate['pfx'] === '' || $certificate['password'] === '') {
            throw new AdnPermanentException('Certificado A1 inválido para CT-e DistDFe.');
        }
    }

    private function looksSensitive(string $message): bool
    {
        return (bool) preg_match('/-----BEGIN|PRIVATE KEY|\.pfx|\.p12|password|senha/i', $message);
    }
}

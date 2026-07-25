<?php

namespace App\Services\Sefaz;

use App\Contracts\SefazNfeManifestationClient;
use App\Domain\Sefaz\ManifestationResultDto;
use App\Enums\NfeManifestationType;
use App\Exceptions\Adn\AdnPermanentException;
use App\Exceptions\Adn\AdnRetryableException;
use App\Services\Adn\CurlMtlsTransport;
use NFePHP\Common\Certificate;
use NFePHP\Common\Signer;
use Throwable;

/**
 * Cliente próprio NFeRecepcaoEvento4 — SOAP 1.2 + mTLS PFX BLOB.
 * Assinatura XML via sped-common (Certificate/Signer em memória; sem PEM em disco).
 */
final class HttpSefazNfeManifestationClient implements SefazNfeManifestationClient
{
    public function __construct(
        private readonly CurlMtlsTransport $transport,
        private readonly ManifestationResponseParser $parser,
    ) {}

    public function register(
        array $certificate,
        string $authorCnpj,
        string $accessKey,
        NfeManifestationType $type,
        ?string $justification = null,
        int $sequence = 1,
    ): ManifestationResultDto {
        if ($type->requiresJustification()) {
            $just = trim((string) $justification);
            $len = mb_strlen($just);
            if ($len < 15 || $len > 255) {
                throw new AdnPermanentException('Justificativa (xJust) deve ter entre 15 e 255 caracteres para operação não realizada.');
            }
        }

        $cnpj = $this->normalizeCnpj($authorCnpj);
        $chave = strtoupper(preg_replace('/\s+/', '', $accessKey) ?? $accessKey);
        if (strlen($chave) !== 44) {
            throw new AdnPermanentException('Chave de acesso NF-e inválida para manifestação.');
        }

        $seq = max(1, min(20, $sequence));
        $seqPad = str_pad((string) $seq, 2, '0', STR_PAD_LEFT);
        $id = 'ID'.$type->tpEvento().$chave.$seqPad;
        $tpAmb = $this->tpAmb();
        $dhEvento = now('America/Sao_Paulo')->format('Y-m-d\TH:i:sP');

        $detJust = '';
        if ($type->requiresJustification() && $justification !== null) {
            $detJust = '<xJust>'.self::xmlEscape(trim($justification)).'</xJust>';
        }

        $evento = '<evento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">'
            .'<infEvento Id="'.$id.'">'
            .'<cOrgao>91</cOrgao>'
            .'<tpAmb>'.$tpAmb.'</tpAmb>'
            .'<CNPJ>'.$cnpj.'</CNPJ>'
            .'<chNFe>'.$chave.'</chNFe>'
            .'<dhEvento>'.$dhEvento.'</dhEvento>'
            .'<tpEvento>'.$type->tpEvento().'</tpEvento>'
            .'<nSeqEvento>'.$seq.'</nSeqEvento>'
            .'<verEvento>1.00</verEvento>'
            .'<detEvento versao="1.00">'
            .'<descEvento>'.$type->descEvento().'</descEvento>'
            .$detJust
            .'</detEvento>'
            .'</infEvento>'
            .'</evento>';

        $signedEvento = $this->signEvent($certificate, $evento);
        // idLote: numérico até 15 dígitos
        $idLote = substr((string) (time() % 1_000_000_000_000_000), 0, 15);
        $envEvento = '<envEvento xmlns="http://www.portalfiscal.inf.br/nfe" versao="1.00">'
            .'<idLote>'.$idLote.'</idLote>'
            .$signedEvento
            .'</envEvento>';

        $ns = (string) config('sefaz.manifest.namespace', 'http://www.portalfiscal.inf.br/nfe/wsdl/NFeRecepcaoEvento4');
        $envelope = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<soap12:Envelope xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema" xmlns:soap12="http://www.w3.org/2003/05/soap-envelope">
  <soap12:Body>
    <nfeRecepcaoEvento xmlns="{$ns}">
      <nfeDadosMsg>{$envEvento}</nfeDadosMsg>
    </nfeRecepcaoEvento>
  </soap12:Body>
</soap12:Envelope>
XML;

        $url = $this->endpointUrl();
        $response = $this->transport->post($url, $certificate, $envelope, [
            'SOAPAction: "'.(string) config('sefaz.manifest.soap_action').'"',
        ]);

        $status = $response['status'];
        if ($status >= 500) {
            throw new AdnRetryableException('SEFAZ RecepcaoEvento temporariamente indisponível.');
        }
        if ($status >= 400) {
            throw new AdnPermanentException('SEFAZ RecepcaoEvento rejeitou a requisição HTTP.');
        }

        return $this->parser->parse($response['body']);
    }

    /**
     * @param  array{pfx: string, password: string}  $certificate
     */
    private function signEvent(array $certificate, string $eventoXml): string
    {
        try {
            $cert = Certificate::readPfx($certificate['pfx'], $certificate['password']);

            // Assina infEvento (Id=...) — padrão MD-e SEFAZ
            return Signer::sign(
                $cert,
                $eventoXml,
                'infEvento',
                'Id',
                OPENSSL_ALGO_SHA1,
                [true, false, null, null],
                'evento'
            );
        } catch (Throwable $e) {
            throw new AdnPermanentException(
                'Falha ao assinar evento de manifestação: '.mb_substr($e->getMessage(), 0, 160),
                0,
                $e
            );
        }
    }

    private function endpointUrl(): string
    {
        $env = (string) config('sefaz.environment', 'production');
        $key = $env === 'homologation' ? 'homologation' : 'production';

        return (string) config('sefaz.manifest.'.$key);
    }

    private function tpAmb(): string
    {
        return (string) config('sefaz.environment', 'production') === 'homologation' ? '2' : '1';
    }

    private function normalizeCnpj(string $cnpj): string
    {
        $c = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $cnpj) ?? $cnpj);
        if (strlen($c) !== 14) {
            throw new AdnPermanentException('CNPJ do autor da manifestação inválido.');
        }

        return $c;
    }

    private static function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

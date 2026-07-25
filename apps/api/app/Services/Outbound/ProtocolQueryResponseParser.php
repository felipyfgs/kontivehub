<?php

namespace App\Services\Outbound;

use App\DTO\Outbound\ProtocolQueryResult;

/**
 * Parser de retConsSitNFe — extrai cStat/chNFe sem expor SOAP bruto ao domínio.
 */
final class ProtocolQueryResponseParser
{
    public function parse(string $xmlOrBody, string $consultedAccessKey): ProtocolQueryResult
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $ok = @$doc->loadXML($xmlOrBody);
        libxml_use_internal_errors($prev);

        if (! $ok) {
            // tenta extrair trecho retConsSitNFe de envelope SOAP
            if (preg_match('/<(?:\w+:)?retConsSitNFe[\s>].*<\/(?:\w+:)?retConsSitNFe>/s', $xmlOrBody, $m)) {
                $prev = libxml_use_internal_errors(true);
                $ok = @$doc->loadXML($m[0]);
                libxml_use_internal_errors($prev);
            }
        }

        if (! $ok) {
            return new ProtocolQueryResult(
                cStat: '000',
                xMotivo: 'Resposta de consulta inválida ou não-XML.',
                consultedAccessKey: $consultedAccessKey,
                sanitized: ['parse' => 'failed'],
            );
        }

        $xp = new \DOMXPath($doc);
        $cStat = $this->first($xp, ['//*[local-name()="cStat"]']) ?? '000';
        $xMotivo = $this->first($xp, ['//*[local-name()="xMotivo"]']) ?? '';
        $chNFe = $this->first($xp, ['//*[local-name()="chNFe"]']);
        $protocol = $this->first($xp, ['//*[local-name()="nProt"]']);
        $tpAmb = $this->first($xp, ['//*[local-name()="tpAmb"]']);

        $consulted = strtoupper(preg_replace('/\s+/', '', $consultedAccessKey) ?? $consultedAccessKey);

        // Em 562/613 a chave verdadeira costuma vir no xMotivo [44]; a chNFe do XML
        // muitas vezes é só a candidata consultada — preferir a do xMotivo.
        $fromMotivo = null;
        if (preg_match('/chNFe[:\s]*([0-9A-Z]{44})/i', $xMotivo, $m)) {
            $fromMotivo = strtoupper($m[1]);
        } elseif (preg_match('/\[([0-9A-Z]{44})\]/i', $xMotivo, $m)) {
            $fromMotivo = strtoupper($m[1]);
        }

        if ($fromMotivo !== null && strlen($fromMotivo) >= 44) {
            $chNFe = $fromMotivo;
        } elseif ($chNFe !== null) {
            $chNFe = strtoupper(preg_replace('/\s+/', '', $chNFe) ?? $chNFe);
            // Se a única chave for a própria candidata e o cStat é rejeição de divergência, não há descoberta útil.
            if (in_array($cStat, ['562', '613'], true) && $chNFe === $consulted) {
                $chNFe = null;
            }
        }

        if ($chNFe !== null && strlen($chNFe) < 44) {
            $chNFe = null;
        }

        return new ProtocolQueryResult(
            cStat: $cStat,
            xMotivo: mb_substr($xMotivo, 0, 500),
            consultedAccessKey: strtoupper($consultedAccessKey),
            returnedAccessKey: $chNFe,
            protocol: $protocol,
            tpAmb: $tpAmb,
            sanitized: [
                'cStat' => $cStat,
                'has_chNFe' => $chNFe !== null,
                'has_protocol' => $protocol !== null,
            ],
        );
    }

    /**
     * @param  list<string>  $paths
     */
    private function first(\DOMXPath $xp, array $paths): ?string
    {
        foreach ($paths as $path) {
            $nodes = $xp->query($path);
            if ($nodes !== false && $nodes->length > 0) {
                $v = trim((string) $nodes->item(0)?->textContent);
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return null;
    }
}

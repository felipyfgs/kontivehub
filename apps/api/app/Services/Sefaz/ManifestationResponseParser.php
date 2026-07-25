<?php

namespace App\Services\Sefaz;

use App\Domain\Sefaz\ManifestationResultDto;
use App\Exceptions\Adn\AdnPermanentException;
use DOMDocument;
use DOMXPath;

/**
 * Parse de retEnvEvento / retEvento (NFeRecepcaoEvento4).
 */
final class ManifestationResponseParser
{
    public function parse(string $soapOrXml): ManifestationResultDto
    {
        $xml = $this->unwrapSoap($soapOrXml);
        $dom = new DOMDocument;
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $ok) {
            throw new AdnPermanentException('Resposta SEFAZ RecepcaoEvento inválida (XML).');
        }

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');

        $cStat = $this->firstText($xp, '//*[local-name()="cStat"]');
        $xMotivo = $this->firstText($xp, '//*[local-name()="xMotivo"]');
        $tpEvento = $this->firstText($xp, '//*[local-name()="tpEvento"]');
        $protocol = $this->firstText($xp, '//*[local-name()="nProt"]');

        // retEvento/infEvento pode ter cStat do evento distinto do lote
        $eventCStat = $this->firstText($xp, '//*[local-name()="retEvento"]//*[local-name()="infEvento"]/*[local-name()="cStat"]')
            ?: $this->firstText($xp, '//*[local-name()="infEvento"]/*[local-name()="cStat"]');
        $eventXMotivo = $this->firstText($xp, '//*[local-name()="retEvento"]//*[local-name()="infEvento"]/*[local-name()="xMotivo"]')
            ?: $this->firstText($xp, '//*[local-name()="infEvento"]/*[local-name()="xMotivo"]');

        if ($cStat === '' && $eventCStat === '') {
            throw new AdnPermanentException('Resposta SEFAZ RecepcaoEvento sem cStat.');
        }

        return new ManifestationResultDto(
            cStat: $cStat !== '' ? $cStat : $eventCStat,
            xMotivo: $xMotivo !== '' ? $xMotivo : ($eventXMotivo ?: 'sem motivo'),
            protocol: $protocol !== '' ? $protocol : null,
            tpEvento: $tpEvento !== '' ? $tpEvento : null,
            eventCStat: $eventCStat !== '' ? $eventCStat : null,
            eventXMotivo: $eventXMotivo !== '' ? $eventXMotivo : null,
            rawXml: $xml,
        );
    }

    private function unwrapSoap(string $body): string
    {
        if (! str_contains($body, 'Envelope') && ! str_contains($body, 'soap')) {
            return $body;
        }

        if (preg_match(
            '/<(?:\w+:)?(?:nfeResultMsg|nfeRecepcaoEventoResult|retEnvEvento)\b[^>]*>(.*)<\/(?:\w+:)?(?:nfeResultMsg|nfeRecepcaoEventoResult|retEnvEvento)>/s',
            $body,
            $m
        )) {
            $inner = html_entity_decode(trim($m[1]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            if (str_starts_with($inner, '<')) {
                return $inner;
            }
        }

        // Fallback: retEnvEvento em qualquer profundidade
        if (preg_match('/<retEnvEvento[\s>].*<\/retEnvEvento>/s', $body, $m)) {
            return $m[0];
        }

        return $body;
    }

    private function firstText(DOMXPath $xp, string $query): string
    {
        $node = $xp->query($query)?->item(0);

        return $node ? trim($node->textContent) : '';
    }
}

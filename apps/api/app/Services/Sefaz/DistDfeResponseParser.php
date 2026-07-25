<?php

namespace App\Services\Sefaz;

use App\Domain\Sefaz\DistDfeDocumentDto;
use App\Domain\Sefaz\DistDfePageDto;
use App\Exceptions\Adn\AdnPermanentException;

/**
 * Parse de retDistDFeInt (SOAP body ou XML interno).
 */
final class DistDfeResponseParser
{
    public function parse(string $xmlOrSoap): DistDfePageDto
    {
        $xml = $this->extractPayloadXml($xmlOrSoap);

        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $ok = @$doc->loadXML($xml);
        libxml_use_internal_errors($prev);

        if (! $ok) {
            throw new AdnPermanentException('Resposta DistDFe malformada.');
        }

        $cStat = $this->text($doc, 'cStat') ?? '';
        $xMotivo = $this->text($doc, 'xMotivo') ?? '';
        $ultRaw = $this->text($doc, 'ultNSU');
        $maxRaw = $this->text($doc, 'maxNSU');
        $ultNsu = $ultRaw !== null && $ultRaw !== '' ? (int) $ultRaw : 0;
        $maxNsu = $maxRaw !== null && $maxRaw !== '' ? (int) $maxRaw : 0;

        $documents = [];
        foreach ($doc->getElementsByTagName('docZip') as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }
            $nsu = (int) ($node->getAttribute('NSU') ?: '0');
            $schema = $node->getAttribute('schema') ?: 'unknown';
            $content = trim($node->textContent);
            $documents[] = new DistDfeDocumentDto(
                nsu: $nsu,
                schema: $schema,
                contentBase64: $content,
                schemaFamily: $this->schemaFamily($schema),
            );
        }

        return new DistDfePageDto(
            cStat: $cStat,
            xMotivo: $xMotivo,
            ultNsu: $ultNsu,
            maxNsu: $maxNsu,
            documents: $documents,
            rawXml: $xml,
        );
    }

    public function schemaFamily(string $schema): string
    {
        $s = strtolower($schema);
        if (str_starts_with($s, 'resnfe')) {
            return 'resNFe';
        }
        if (str_starts_with($s, 'procnfe')) {
            return 'procNFe';
        }
        if (str_starts_with($s, 'resevento') && ! str_contains($s, 'cte')) {
            return 'resEvento';
        }
        if (str_starts_with($s, 'proceventonfe')) {
            return 'procEventoNFe';
        }
        // CT-e
        if (str_starts_with($s, 'rescte')) {
            return 'resCTe';
        }
        if (str_starts_with($s, 'proccte') || str_starts_with($s, 'cte_')) {
            return 'procCTe';
        }
        if (str_starts_with($s, 'proceventocte') || str_starts_with($s, 'reteventocte')) {
            return 'procEventoCTe';
        }
        // MDF-e
        if (str_starts_with($s, 'resmdfe')) {
            return 'resMDFe';
        }
        if (str_starts_with($s, 'procmdfe') || str_starts_with($s, 'mdfe_')) {
            return 'procMDFe';
        }
        if (str_starts_with($s, 'proceventomdfe') || str_starts_with($s, 'reteventomdfe')) {
            return 'procEventoMDFe';
        }

        return 'unknown';
    }

    private function extractPayloadXml(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new AdnPermanentException('Resposta DistDFe vazia.');
        }

        // Prefer inner retDistDFeInt if present inside SOAP
        if (preg_match('/<retDistDFeInt[\s>].*<\/retDistDFeInt>/s', $raw, $m)) {
            return $m[0];
        }
        if (str_contains($raw, 'retDistDFeInt')) {
            return $raw;
        }

        return $raw;
    }

    private function text(\DOMDocument $doc, string $localName): ?string
    {
        $list = $doc->getElementsByTagName($localName);
        if ($list->length === 0) {
            return null;
        }

        $v = trim($list->item(0)?->textContent ?? '');

        return $v === '' ? null : $v;
    }
}

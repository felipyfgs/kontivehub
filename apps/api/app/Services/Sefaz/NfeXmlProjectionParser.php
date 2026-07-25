<?php

namespace App\Services\Sefaz;

use Carbon\CarbonImmutable;

/**
 * Extração tolerante de campos de catálogo a partir de resNFe / procNFe / evento.
 *
 * @return array<string, mixed>
 */
final class NfeXmlProjectionParser
{
    /**
     * @return array{
     *   access_key: ?string,
     *   number: ?string,
     *   series: ?string,
     *   model: string,
     *   tp_nf: ?string,
     *   tp_amb: ?string,
     *   issuer_cnpj: ?string,
     *   issuer_name: ?string,
     *   recipient_cnpj: ?string,
     *   recipient_name: ?string,
     *   aut_xml_cnpjs: list<string>,
     *   issued_at: ?CarbonImmutable,
     *   total_amount: ?string,
     *   status: string,
     *   official_status_code: ?string,
     *   is_summary: bool,
     *   event_type: ?string,
     *   event_at: ?CarbonImmutable,
     * }
     */
    public function parse(string $xml, string $schemaFamily): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $ok = @$doc->loadXML($xml);
        libxml_use_internal_errors($prev);

        $base = [
            'access_key' => null,
            'number' => null,
            'series' => null,
            'model' => '55',
            'tp_nf' => null,
            'tp_amb' => null,
            'issuer_cnpj' => null,
            'issuer_name' => null,
            'recipient_cnpj' => null,
            'recipient_name' => null,
            'aut_xml_cnpjs' => [],
            'issued_at' => null,
            'total_amount' => null,
            'status' => 'UNKNOWN',
            'official_status_code' => null,
            'is_summary' => $schemaFamily === 'resNFe',
            'event_type' => null,
            'event_at' => null,
        ];

        if (! $ok) {
            return $base;
        }

        return match ($schemaFamily) {
            'resNFe' => array_merge($base, $this->parseResNfe($doc)),
            'procNFe' => array_merge($base, $this->parseProcNfe($doc)),
            'procEventoNFe', 'resEvento' => array_merge($base, $this->parseEvento($doc, $schemaFamily)),
            default => $base,
        };
    }

    /** @return array<string, mixed> */
    private function parseResNfe(\DOMDocument $doc): array
    {
        $key = $this->first($doc, ['//*[local-name()="chNFe"]']);
        $cSit = $this->first($doc, ['//*[local-name()="cSitNFe"]']);
        $status = match ($cSit) {
            '1' => 'ACTIVE',
            '2' => 'DENIED',
            '3' => 'CANCELLED',
            default => 'SUMMARY',
        };

        return [
            'access_key' => $key ? strtoupper($key) : null,
            'issuer_cnpj' => $this->cnpj($doc, ['//*[local-name()="CNPJ"]', '//*[local-name()="CPF"]']),
            'issuer_name' => $this->first($doc, ['//*[local-name()="xNome"]']),
            'issued_at' => $this->date($this->first($doc, ['//*[local-name()="dhEmi"]', '//*[local-name()="dEmi"]'])),
            'total_amount' => $this->first($doc, ['//*[local-name()="vNF"]']),
            'status' => $status,
            'official_status_code' => $cSit,
            'is_summary' => true,
            'manifestation_status' => 'PENDING_MANIFESTATION',
        ];
    }

    /** @return array<string, mixed> */
    private function parseProcNfe(\DOMDocument $doc): array
    {
        $key = $this->first($doc, [
            '//*[local-name()="infNFe"]/@Id',
            '//*[local-name()="chNFe"]',
        ]);
        if ($key && str_starts_with(strtoupper($key), 'NFE')) {
            $key = substr($key, 3);
        }

        $cStat = $this->first($doc, ['//*[local-name()="protNFe"]//*[local-name()="cStat"]', '//*[local-name()="cStat"]']);
        $status = match ($cStat) {
            '100', '150' => 'ACTIVE',
            '101', '151', '155' => 'CANCELLED',
            '110', '301', '302' => 'DENIED',
            default => 'UNKNOWN',
        };

        return [
            'access_key' => $key ? strtoupper(preg_replace('/[^A-Z0-9]/i', '', $key) ?? $key) : null,
            'number' => $this->first($doc, ['//*[local-name()="ide"]/*[local-name()="nNF"]']),
            'series' => $this->first($doc, ['//*[local-name()="ide"]/*[local-name()="serie"]']),
            'model' => $this->first($doc, ['//*[local-name()="ide"]/*[local-name()="mod"]']) ?? '55',
            'tp_nf' => $this->first($doc, ['//*[local-name()="ide"]/*[local-name()="tpNF"]']),
            'tp_amb' => $this->first($doc, [
                '//*[local-name()="ide"]/*[local-name()="tpAmb"]',
                '//*[local-name()="protNFe"]//*[local-name()="tpAmb"]',
            ]),
            'issuer_cnpj' => $this->cnpj($doc, [
                '//*[local-name()="emit"]//*[local-name()="CNPJ"]',
                '//*[local-name()="emit"]//*[local-name()="CPF"]',
            ]),
            'issuer_name' => $this->first($doc, ['//*[local-name()="emit"]/*[local-name()="xNome"]']),
            'recipient_cnpj' => $this->cnpj($doc, [
                '//*[local-name()="dest"]//*[local-name()="CNPJ"]',
                '//*[local-name()="dest"]//*[local-name()="CPF"]',
            ]),
            'recipient_name' => $this->first($doc, ['//*[local-name()="dest"]/*[local-name()="xNome"]']),
            'aut_xml_cnpjs' => $this->allAutXmlCnpjs($doc),
            'issued_at' => $this->date($this->first($doc, ['//*[local-name()="ide"]/*[local-name()="dhEmi"]'])),
            'total_amount' => $this->first($doc, ['//*[local-name()="ICMSTot"]/*[local-name()="vNF"]', '//*[local-name()="vNF"]']),
            'status' => $status,
            'official_status_code' => $cStat,
            'is_summary' => false,
            'manifestation_status' => null,
        ];
    }

    /**
     * Todas as ocorrências autXML/CNPJ (ou CPF) normalizadas uppercase.
     *
     * @return list<string>
     */
    public function allAutXmlCnpjs(\DOMDocument $doc): array
    {
        $xp = new \DOMXPath($doc);
        $nodes = $xp->query('//*[local-name()="autXML"]/*[local-name()="CNPJ" or local-name()="CPF"]');
        if (! $nodes || $nodes->length === 0) {
            return [];
        }

        $out = [];
        foreach ($nodes as $node) {
            $raw = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($node->textContent ?? '')) ?? '');
            if ($raw !== '' && ! in_array($raw, $out, true)) {
                $out[] = $raw;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function parseEvento(\DOMDocument $doc, string $family): array
    {
        $key = $this->first($doc, ['//*[local-name()="chNFe"]']);
        $tp = $this->first($doc, ['//*[local-name()="tpEvento"]']);

        return [
            'access_key' => $key ? strtoupper($key) : null,
            'is_summary' => $family === 'resEvento',
            'event_type' => $tp,
            'event_at' => $this->date($this->first($doc, ['//*[local-name()="dhEvento"]', '//*[local-name()="dhRegEvento"]'])),
            'status' => $tp === '110111' ? 'CANCELLED' : 'EVENT',
            'official_status_code' => $tp,
        ];
    }

    private function first(\DOMDocument $doc, array $xpaths): ?string
    {
        $xp = new \DOMXPath($doc);
        foreach ($xpaths as $q) {
            if (str_contains($q, '/@')) {
                [$path, $attr] = explode('/@', $q, 2);
                $nodes = $xp->query($path);
                if ($nodes && $nodes->length > 0) {
                    $el = $nodes->item(0);
                    if ($el instanceof \DOMElement && $el->hasAttribute($attr)) {
                        $v = trim($el->getAttribute($attr));
                        if ($v !== '') {
                            return $v;
                        }
                    }
                }

                continue;
            }
            $nodes = $xp->query($q);
            if ($nodes && $nodes->length > 0) {
                $v = trim($nodes->item(0)?->textContent ?? '');
                if ($v !== '') {
                    return $v;
                }
            }
        }

        return null;
    }

    private function cnpj(\DOMDocument $doc, array $xpaths): ?string
    {
        $v = $this->first($doc, $xpaths);
        if ($v === null) {
            return null;
        }

        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $v) ?? $v);
    }

    private function date(?string $raw): ?CarbonImmutable
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}

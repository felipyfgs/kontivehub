<?php

namespace App\Services\Outbound;

/**
 * Parser de inutilização: 102, 241, 256, 563 e ambíguos.
 */
final class InutilizationResponseParser
{
    /**
     * @return array{
     *   cStat: string,
     *   xMotivo: string,
     *   outcome: 'INUTILIZED'|'PROVEN_USED'|'REJECTED'|'AMBIGUOUS',
     *   protocol: ?string
     * }
     */
    public function parse(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $ok = @$doc->loadXML($xml);
        libxml_use_internal_errors($prev);

        $cStat = '000';
        $xMotivo = '';
        $protocol = null;

        if ($ok) {
            $xp = new \DOMXPath($doc);
            $cStat = $this->first($xp, '//*[local-name()="cStat"]') ?? '000';
            $xMotivo = $this->first($xp, '//*[local-name()="xMotivo"]') ?? '';
            $protocol = $this->first($xp, '//*[local-name()="nProt"]');
        }

        $outcome = match ($cStat) {
            '102' => 'INUTILIZED',
            '241' => 'PROVEN_USED',
            '256', '563' => 'REJECTED',
            default => 'AMBIGUOUS',
        };

        return [
            'cStat' => $cStat,
            'xMotivo' => mb_substr($xMotivo, 0, 500),
            'outcome' => $outcome,
            'protocol' => $protocol,
        ];
    }

    private function first(\DOMXPath $xp, string $path): ?string
    {
        $nodes = $xp->query($path);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $v = trim((string) $nodes->item(0)?->textContent);

        return $v !== '' ? $v : null;
    }
}

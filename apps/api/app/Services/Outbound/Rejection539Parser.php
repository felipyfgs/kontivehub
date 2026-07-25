<?php

namespace App\Services\Outbound;

/**
 * Parser da rejeição 539 — valida chave retornada antes de KEY_DISCOVERED.
 */
final class Rejection539Parser
{
    public function __construct(
        private readonly AccessKeyCandidateBuilder $keyBuilder,
    ) {}

    /**
     * @return array{cStat: string, xMotivo: string, access_key: ?string, valid: bool}
     */
    public function parse(
        string $xml,
        string $cuf,
        string $cnpj,
        string $model,
        int $series,
        int $nnf,
        string $tpEmis,
    ): array {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $ok = @$doc->loadXML($xml);
        libxml_use_internal_errors($prev);

        $cStat = '000';
        $xMotivo = '';
        $key = null;

        if ($ok) {
            $xp = new \DOMXPath($doc);
            $cStat = $this->first($xp, '//*[local-name()="cStat"]') ?? '000';
            $xMotivo = $this->first($xp, '//*[local-name()="xMotivo"]') ?? '';
            $key = $this->first($xp, '//*[local-name()="chNFe"]');
            if (($key === null || strlen($key) < 44) && preg_match('/([0-9A-Z]{44})/i', $xMotivo, $m)) {
                $key = strtoupper($m[1]);
            }
        }

        if ($key !== null) {
            $key = strtoupper(preg_replace('/\s+/', '', $key) ?? $key);
        }

        $valid = $key !== null
            && $cStat === '539'
            && $this->keyBuilder->matchesIdentity($key, $cuf, $cnpj, $model, $series, $nnf, $tpEmis);

        return [
            'cStat' => $cStat,
            'xMotivo' => mb_substr($xMotivo, 0, 500),
            'access_key' => $valid ? $key : null,
            'valid' => $valid,
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

<?php

namespace App\Services\Outbound;

use App\Enums\OutboundFiscalModel;
use App\Models\Establishment;
use App\Services\Sefaz\NfeXmlProjectionParser;
use RuntimeException;

/**
 * Validador estrito de pacote oficial MA: somente procNFe 55/65 com protocolo.
 */
final class MaOfficialPackageValidator
{
    public function __construct(
        private readonly NfeXmlProjectionParser $parser,
    ) {}

    /**
     * @return array{
     *   access_key: string,
     *   model: OutboundFiscalModel,
     *   series: int,
     *   nnf: int,
     *   issuer_cnpj: string,
     *   cstat: string,
     *   is_cancelled: bool,
     * }
     */
    public function validateXml(string $xml, Establishment $establishment, string $environment): array
    {
        if (! str_contains($xml, 'nfeProc') && ! str_contains($xml, 'protNFe')) {
            throw new RuntimeException('Pacote deve conter procNFe original com protocolo.');
        }

        $parsed = $this->parser->parse($xml, 'procNFe');
        $key = strtoupper(preg_replace('/\s+/', '', (string) ($parsed['access_key'] ?? '')) ?? '');
        if (strlen($key) < 44) {
            throw new RuntimeException('Chave de acesso inválida no XML do pacote.');
        }

        if (substr($key, 0, 2) !== '21') {
            throw new RuntimeException('XML do pacote deve ser cUF=21 (MA).');
        }

        $modelCode = (string) ($parsed['model'] ?? substr($key, 20, 2));
        $model = OutboundFiscalModel::tryFrom($modelCode);
        if ($model === null) {
            throw new RuntimeException('Modelo deve ser 55 ou 65.');
        }

        $issuer = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) ($parsed['issuer_cnpj'] ?? '')) ?? '');
        $estab = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $establishment->cnpj) ?? '');
        if ($issuer === '' || $issuer !== $estab) {
            throw new RuntimeException('Emitente do XML diverge do estabelecimento.');
        }

        $tpAmb = $this->first($xml, '//*[local-name()="tpAmb"]') ?? '1';
        $xmlEnv = $tpAmb === '2' ? 'homologation' : 'production';
        if ($xmlEnv !== $environment) {
            throw new RuntimeException('Ambiente do XML diverge do perfil.');
        }

        $cstat = (string) ($parsed['official_status_code'] ?? $this->first($xml, '//*[local-name()="cStat"]') ?? '');
        $isCancelled = in_array($cstat, ['101', '135', '155'], true)
            || ($parsed['status'] ?? '') === 'CANCELLED';

        if (! in_array($cstat, ['100', '150', '101', '110', '135', '155'], true) && ! $isCancelled) {
            // Ainda aceita se houver protocolo e chave — status pode variar
            if ($this->first($xml, '//*[local-name()="nProt"]') === null) {
                throw new RuntimeException('XML sem protocolo de autorização/cancelamento verificável.');
            }
        }

        return [
            'access_key' => $key,
            'model' => $model,
            'series' => (int) ($parsed['series'] ?? 0),
            'nnf' => (int) ($parsed['number'] ?? 0),
            'issuer_cnpj' => $issuer,
            'cstat' => $cstat,
            'is_cancelled' => $isCancelled,
        ];
    }

    private function first(string $xml, string $path): ?string
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $ok = @$doc->loadXML($xml);
        libxml_use_internal_errors($prev);
        if (! $ok) {
            return null;
        }
        $xp = new \DOMXPath($doc);
        $nodes = $xp->query($path);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $v = trim((string) $nodes->item(0)?->textContent);

        return $v !== '' ? $v : null;
    }
}

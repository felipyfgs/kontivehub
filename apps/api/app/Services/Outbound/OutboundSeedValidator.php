<?php

namespace App\Services\Outbound;

use App\Enums\OutboundFiscalModel;
use App\Models\Establishment;
use App\Services\Sefaz\NfeXmlProjectionParser;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Valida XML-semente autorizado para monitoramento de série (sem transmissão).
 */
final class OutboundSeedValidator
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
     *   tp_emis: string,
     *   cuf: string,
     *   issuer_cnpj: string,
     *   issued_at: CarbonImmutable,
     *   tp_amb: string,
     *   environment: string,
     *   cstat: string,
     *   protocol: ?string,
     * }
     */
    public function validate(string $xml, Establishment $establishment, ?string $expectedEnvironment = null): array
    {
        $hasProtocolEnvelope = str_contains($xml, 'protNFe') || str_contains($xml, 'nfeProc');
        if (! $hasProtocolEnvelope) {
            throw new RuntimeException('Semente deve ser procNFe com protocolo; NFe isolada não é aceita.');
        }

        $parsed = $this->parser->parse($xml, 'procNFe');
        $accessKey = $this->normalizeKey($parsed['access_key'] ?? null);
        if ($accessKey === null || strlen($accessKey) < 44) {
            throw new RuntimeException('Chave de acesso ausente ou inválida na semente.');
        }

        $cuf = substr($accessKey, 0, 2);
        if ($cuf !== '21') {
            throw new RuntimeException('Semente deve ser de UF MA (cUF=21).');
        }

        $modelCode = (string) ($parsed['model'] ?? substr($accessKey, 20, 2));
        $model = OutboundFiscalModel::tryFrom($modelCode);
        if ($model === null) {
            throw new RuntimeException('Modelo deve ser 55 ou 65.');
        }

        $issuer = $this->normalizeCnpj($parsed['issuer_cnpj'] ?? null);
        $estabCnpj = $this->normalizeCnpj($establishment->cnpj);
        if ($issuer === null || $estabCnpj === null || $issuer !== $estabCnpj) {
            throw new RuntimeException('Emitente da semente deve coincidir com o CNPJ do estabelecimento.');
        }

        $uf = strtoupper((string) ($establishment->address_state ?? ''));
        if ($uf !== '' && $uf !== 'MA') {
            throw new RuntimeException('Estabelecimento deve ser UF MA.');
        }

        $series = (int) ($parsed['series'] ?? 0);
        $nnf = (int) ($parsed['number'] ?? 0);
        if ($series < 0 || $nnf < 1) {
            throw new RuntimeException('Série e nNF inválidos na semente.');
        }

        $issuedAt = $parsed['issued_at'] ?? null;
        if (! $issuedAt instanceof CarbonImmutable) {
            // Fallback: dhEmi pode não estar sob ide/* em alguns fixtures
            $raw = $this->xpathFirst($xml, ['//*[local-name()="dhEmi"]', '//*[local-name()="dEmi"]']);
            if ($raw !== null) {
                try {
                    $issuedAt = CarbonImmutable::parse($raw);
                } catch (\Throwable) {
                    $issuedAt = null;
                }
            }
        }
        if (! $issuedAt instanceof CarbonImmutable) {
            throw new RuntimeException('Data de emissão ausente na semente.');
        }

        $maxAge = (int) config('sefaz.ma_outbound.seed_max_age_days', 60);
        if ($issuedAt->lt(now()->subDays($maxAge))) {
            throw new RuntimeException("Semente com mais de {$maxAge} dias não é aceita.");
        }

        $tpNf = $this->xpathFirst($xml, ['//*[local-name()="tpNF"]']) ?? '1';
        if ($tpNf !== '1') {
            throw new RuntimeException('Semente deve ser saída (tpNF=1).');
        }

        $tpEmis = $this->xpathFirst($xml, ['//*[local-name()="tpEmis"]']) ?? '1';
        $tpAmb = $this->xpathFirst($xml, ['//*[local-name()="tpAmb"]']) ?? '1';
        $environment = $tpAmb === '2' ? 'homologation' : 'production';
        if ($expectedEnvironment !== null && $expectedEnvironment !== $environment) {
            throw new RuntimeException('Ambiente da semente diverge do perfil.');
        }

        $cstat = (string) ($parsed['official_status_code'] ?? $this->xpathFirst($xml, ['//*[local-name()="cStat"]']) ?? '');
        if (! in_array($cstat, ['100', '150'], true)) {
            throw new RuntimeException('Semente deve estar autorizada (cStat 100/150).');
        }

        $protocol = $this->xpathFirst($xml, ['//*[local-name()="nProt"]']);

        return [
            'access_key' => $accessKey,
            'model' => $model,
            'series' => $series,
            'nnf' => $nnf,
            'tp_emis' => $tpEmis,
            'cuf' => $cuf,
            'issuer_cnpj' => $issuer,
            'issued_at' => $issuedAt,
            'tp_amb' => $tpAmb,
            'environment' => $environment,
            'cstat' => $cstat,
            'protocol' => $protocol,
        ];
    }

    private function normalizeKey(?string $key): ?string
    {
        if ($key === null || $key === '') {
            return null;
        }

        return strtoupper(preg_replace('/\s+/', '', $key) ?? $key);
    }

    private function normalizeCnpj(?string $cnpj): ?string
    {
        if ($cnpj === null || $cnpj === '') {
            return null;
        }

        return strtoupper(preg_replace('/[^A-Z0-9]/i', '', $cnpj) ?? $cnpj);
    }

    /**
     * @param  list<string>  $paths
     */
    private function xpathFirst(string $xml, array $paths): ?string
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $ok = @$doc->loadXML($xml);
        libxml_use_internal_errors($prev);
        if (! $ok) {
            return null;
        }
        $xp = new \DOMXPath($doc);
        foreach ($paths as $path) {
            $nodes = $xp->query($path);
            if ($nodes !== false && $nodes->length > 0) {
                $v = trim((string) $nodes->item(0)?->textContent);

                return $v !== '' ? $v : null;
            }
        }

        return null;
    }
}

<?php

namespace App\Services\Adn;

use App\Enums\FiscalRole;
use App\Support\NfseNoteStatus;
use Carbon\CarbonImmutable;

final class NfseXmlParser
{
    /**
     * @return array{
     *   access_key: ?string,
     *   number: ?string,
     *   issuer_cnpj: ?string,
     *   issuer_name: ?string,
     *   taker_cnpj: ?string,
     *   taker_name: ?string,
     *   intermediary_cnpj: ?string,
     *   intermediary_name: ?string,
     *   competence: ?string,
     *   issued_at: ?CarbonImmutable,
     *   service_amount: ?string,
     *   issue_location: ?string,
     *   service_location: ?string,
     *   official_status_code: ?string,
     *   status: string,
     *   parse_status: string,
     *   parse_alert: ?string,
     *   fiscal_role_for: callable(string): ?FiscalRole
     * }
     */
    public function parseNote(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $ok = @$doc->loadXML($xml);
        libxml_use_internal_errors($prev);

        if (! $ok) {
            return $this->failed('XML malformado.');
        }

        // Nacional: chave em infNFSe/@Id (prefixo NFS) ou chNFSe — sempre uppercase.
        $accessKey = $this->first($doc, ['//*[local-name()="chNFSe"]', '//*[local-name()="chaveAcesso"]']);
        $accessKey = $accessKey !== null ? strtoupper($accessKey) : $this->accessKeyFromInfId($doc);

        $number = $this->first($doc, [
            '//*[local-name()="infNFSe"]/*[local-name()="nNFSe"]',
            '//*[local-name()="nNFSe"]',
        ]);

        $issuer = $this->cnpj($doc, [
            '//*[local-name()="infNFSe"]/*[local-name()="emit"]/*[local-name()="CNPJ"]',
            '//*[local-name()="emit"]//*[local-name()="CNPJ"]',
            '//*[local-name()="prest"]//*[local-name()="CNPJ"]',
            '//*[local-name()="prestador"]//*[local-name()="CNPJ"]',
        ]);
        $issuerName = $this->first($doc, [
            '//*[local-name()="infNFSe"]/*[local-name()="emit"]/*[local-name()="xNome"]',
            '//*[local-name()="emit"]/*[local-name()="xNome"]',
            '//*[local-name()="prest"]/*[local-name()="xNome"]',
        ]);

        $taker = $this->cnpj($doc, [
            '//*[local-name()="toma"]//*[local-name()="CNPJ"]',
            '//*[local-name()="tomador"]//*[local-name()="CNPJ"]',
            '//*[local-name()="dest"]//*[local-name()="CNPJ"]',
        ]);
        $takerName = $this->first($doc, [
            '//*[local-name()="toma"]/*[local-name()="xNome"]',
            '//*[local-name()="toma"]//*[local-name()="xNome"]',
            '//*[local-name()="tomador"]//*[local-name()="xNome"]',
        ]);

        $intermediary = $this->cnpj($doc, [
            '//*[local-name()="intermediario"]//*[local-name()="CNPJ"]',
            '//*[local-name()="intermediarioServico"]//*[local-name()="CNPJ"]',
        ]);
        $intermediaryName = $this->first($doc, [
            '//*[local-name()="intermediario"]//*[local-name()="xNome"]',
            '//*[local-name()="intermediarioServico"]//*[local-name()="xNome"]',
        ]);

        $competence = $this->first($doc, [
            '//*[local-name()="dCompet"]',
            '//*[local-name()="competencia"]',
        ]);
        // Preferir emissão da DPS (dhEmi), não dhProc (processamento ADN).
        $issuedRaw = $this->first($doc, [
            '//*[local-name()="infDPS"]/*[local-name()="dhEmi"]',
            '//*[local-name()="dhEmi"]',
            '//*[local-name()="dataEmissao"]',
            '//*[local-name()="dhProc"]',
        ]);
        // Valor: preferir vServPrest/vServ do DPS; depois vLiq.
        $amount = $this->first($doc, [
            '//*[local-name()="vServPrest"]/*[local-name()="vServ"]',
            '//*[local-name()="valores"]/*[local-name()="vLiq"]',
            '//*[local-name()="vServ"]',
            '//*[local-name()="valorServicos"]',
        ]);

        $issueLocation = $this->first($doc, [
            '//*[local-name()="infNFSe"]/*[local-name()="xLocEmi"]',
            '//*[local-name()="xLocEmi"]',
        ]);
        $serviceLocation = $this->first($doc, [
            '//*[local-name()="infNFSe"]/*[local-name()="xLocPrestacao"]',
            '//*[local-name()="xLocPrestacao"]',
        ]);

        $cStat = $this->first($doc, [
            '//*[local-name()="infNFSe"]/*[local-name()="cStat"]',
            '//*[local-name()="cStat"]',
        ]);
        $status = $this->mapOfficialStatus($cStat);

        $parseStatus = 'OK';
        $alert = null;
        if ($accessKey === null) {
            $parseStatus = 'REVIEW';
            $alert = 'Chave de acesso não encontrada; XML preservado.';
        }

        return [
            'access_key' => $accessKey,
            'number' => $number,
            'issuer_cnpj' => $issuer,
            'issuer_name' => $this->truncateName($issuerName),
            'taker_cnpj' => $taker,
            'taker_name' => $this->truncateName($takerName),
            'intermediary_cnpj' => $intermediary,
            'intermediary_name' => $this->truncateName($intermediaryName),
            'competence' => $this->normalizeCompetence($competence),
            'issued_at' => $issuedRaw ? CarbonImmutable::parse($issuedRaw) : null,
            'service_amount' => $this->normalizeAmount($amount),
            'issue_location' => $this->truncateName($issueLocation, 120),
            'service_location' => $this->truncateName($serviceLocation, 120),
            'official_status_code' => $cStat,
            'status' => $status,
            'parse_status' => $parseStatus,
            'parse_alert' => $alert,
            'fiscal_role_for' => function (string $establishmentCnpj) use ($issuer, $taker, $intermediary): ?FiscalRole {
                $c = strtoupper($establishmentCnpj);
                if ($issuer === $c) {
                    return FiscalRole::Issuer;
                }
                if ($taker === $c) {
                    return FiscalRole::Taker;
                }
                if ($intermediary === $c) {
                    return FiscalRole::Intermediary;
                }

                return null;
            },
        ];
    }

    /**
     * @return array{access_key: ?string, event_type: ?string, event_at: ?CarbonImmutable, status: ?string, parse_status: string, parse_alert: ?string}
     */
    public function parseEvent(string $xml): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $ok = @$doc->loadXML($xml);
        libxml_use_internal_errors($prev);

        if (! $ok) {
            return [
                'access_key' => null,
                'event_type' => null,
                'event_at' => null,
                'status' => null,
                'parse_status' => 'FAILED',
                'parse_alert' => 'XML de evento malformado.',
            ];
        }

        $accessKey = $this->first($doc, ['//*[local-name()="chNFSe"]', '//*[local-name()="chaveAcesso"]']);
        $accessKey = $accessKey !== null ? strtoupper($accessKey) : $this->accessKeyFromInfId($doc);

        return [
            'access_key' => $accessKey,
            'event_type' => $this->first($doc, ['//*[local-name()="tpEvento"]', '//*[local-name()="tipoEvento"]']),
            'event_at' => ($raw = $this->first($doc, ['//*[local-name()="dhEvento"]'])) ? CarbonImmutable::parse($raw) : null,
            'status' => $this->first($doc, ['//*[local-name()="cStat"]']),
            'parse_status' => 'OK',
            'parse_alert' => null,
        ];
    }

    /**
     * Layout nacional: infNFSe Id="NFS{chave50}" (ou similar).
     */
    private function accessKeyFromInfId(\DOMDocument $doc): ?string
    {
        $xpath = new \DOMXPath($doc);
        foreach (['infNFSe', 'infEvento', 'infDFe'] as $local) {
            $nodes = $xpath->query('//*[local-name()="'.$local.'"]');
            if (! $nodes || $nodes->length === 0) {
                continue;
            }
            $el = $nodes->item(0);
            if (! $el instanceof \DOMElement) {
                continue;
            }
            $id = trim($el->getAttribute('Id') ?: $el->getAttribute('id'));
            if ($id === '') {
                continue;
            }
            if (preg_match('/^NFS([0-9A-Za-z]{44,50})$/i', $id, $m)) {
                return strtoupper($m[1]);
            }
            if (preg_match('/^([0-9A-Za-z]{44,50})$/', $id, $m)) {
                return strtoupper($m[1]);
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $expressions
     */
    private function first(\DOMDocument $doc, array $expressions): ?string
    {
        $xpath = new \DOMXPath($doc);
        foreach ($expressions as $expr) {
            $nodes = $xpath->query($expr);
            if ($nodes && $nodes->length > 0) {
                $value = trim($nodes->item(0)?->textContent ?? '');
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $expressions
     */
    private function cnpj(\DOMDocument $doc, array $expressions): ?string
    {
        $value = $this->first($doc, $expressions);

        return $value ? strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $value) ?? '') : null;
    }

    private function normalizeCompetence(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})/', $value, $m)) {
            return $m[1].'-'.$m[2];
        }
        if (preg_match('/^(\d{2})\/(\d{4})$/', $value, $m)) {
            return $m[2].'-'.$m[1];
        }

        return $value;
    }

    private function normalizeAmount(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        // Aceita 1234.56 ou 1.234,56
        $clean = str_replace([' ', "\u{00A0}"], '', $value);
        if (str_contains($clean, ',') && str_contains($clean, '.')) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } elseif (str_contains($clean, ',')) {
            $clean = str_replace(',', '.', $clean);
        }
        if (! is_numeric($clean)) {
            return $value;
        }

        return number_format((float) $clean, 2, '.', '');
    }

    /**
     * cStat da NFS-e Nacional (documento) → status operacional.
     * 100 Gerada · 101 Substituta · 102 Decisão judicial · 103 Avulsa.
     * Cancelamento real vem de eventos (não do cStat 101).
     *
     * @see NfseNoteStatus
     */
    private function mapOfficialStatus(?string $cStat): string
    {
        return NfseNoteStatus::fromCStat($cStat);
    }

    private function truncateName(?string $value, int $max = 255): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
    }

    /**
     * @return array<string, mixed>
     */
    private function failed(string $alert): array
    {
        return [
            'access_key' => null,
            'number' => null,
            'issuer_cnpj' => null,
            'issuer_name' => null,
            'taker_cnpj' => null,
            'taker_name' => null,
            'intermediary_cnpj' => null,
            'intermediary_name' => null,
            'competence' => null,
            'issued_at' => null,
            'service_amount' => null,
            'issue_location' => null,
            'service_location' => null,
            'official_status_code' => null,
            'status' => 'UNKNOWN',
            'parse_status' => 'FAILED',
            'parse_alert' => $alert,
            'fiscal_role_for' => fn () => null,
        ];
    }
}

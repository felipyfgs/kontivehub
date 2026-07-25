<?php

namespace App\Services\Sefaz;

use App\Domain\Sefaz\CtePartyIdentity;
use App\Enums\DocumentDirection;
use App\Enums\FiscalRole;
use Carbon\CarbonImmutable;

/**
 * Extração tolerante de campos de catálogo a partir de procCTe / resCTe / evento.
 *
 * Papéis CT-e são listados explicitamente; não há fallback para TAKER.
 * ISSUER no DistDFe do próprio cliente é responsabilidade do page processor (quarentena).
 *
 * @return array<string, mixed>
 */
final class CteXmlProjectionParser
{
    private const REDACTED_KEY_LITERAL = '99999999999999999999999999999999999999999999';

    /**
     * @return array{
     *   access_key: ?string,
     *   number: ?string,
     *   series: ?string,
     *   model: string,
     *   issuer_cnpj: ?string,
     *   issuer_name: ?string,
     *   taker_cnpj: ?string,
     *   taker_name: ?string,
     *   effective_taker_cnpj: ?string,
     *   sender_cnpj: ?string,
     *   sender_name: ?string,
     *   recipient_cnpj: ?string,
     *   recipient_name: ?string,
     *   expeditor_cnpj: ?string,
     *   expeditor_name: ?string,
     *   receiver_cnpj: ?string,
     *   receiver_name: ?string,
     *   autxml_cnpjs: list<string>,
     *   related_access_keys: list<string>,
     *   has_official_redaction: bool,
     *   issued_at: ?CarbonImmutable,
     *   total_amount: ?string,
     *   status: string,
     *   official_status_code: ?string,
     *   protocol_number: ?string,
     *   schema_version: ?string,
     *   is_summary: bool,
     *   is_event: bool,
     *   event_type: ?string,
     *   event_sequence: ?int,
     *   environment: ?string,
     *   fiscal_role: ?FiscalRole,
     *   direction: DocumentDirection,
     *   matched_roles: list<FiscalRole>,
     *   parties: list<CtePartyIdentity>,
     * }
     */
    public function parse(string $xml, string $schemaFamily, ?string $establishmentCnpj = null): array
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $ok = @$doc->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_use_internal_errors($prev);

        $base = $this->emptyProjection($schemaFamily === 'resCTe');

        if (! $ok) {
            return $base;
        }

        $parsed = match ($schemaFamily) {
            'resCTe' => array_merge($base, $this->parseResCte($doc)),
            'procCTe', 'CTe', 'cteProc' => array_merge($base, $this->parseProcCte($doc)),
            'procEventoCTe', 'retEventoCTe' => array_merge($base, $this->parseEvento($doc)),
            default => array_merge($base, $this->parseProcCte($doc)),
        };

        $parties = $this->buildParties($parsed);
        $parsed['parties'] = $parties;
        $parsed['autxml_cnpjs'] = $parsed['autxml_cnpjs'] ?? [];

        $matched = $this->matchRoles($parties, $establishmentCnpj);
        $parsed['matched_roles'] = $matched;

        // Compat: um papel "primário" se houver exatamente um match; senão null (sem inventar TAKER)
        $primary = count($matched) === 1 ? $matched[0] : (count($matched) > 1 ? $this->preferRole($matched) : null);
        $parsed['fiscal_role'] = $primary;
        $parsed['direction'] = DocumentDirection::fromFiscalRole($primary);

        return $parsed;
    }

    /**
     * Todos os papéis comprovados para o CNPJ (permite múltiplos).
     *
     * @return list<FiscalRole>
     */
    public function resolveRoles(string $xml, string $schemaFamily, string $cnpj): array
    {
        $parsed = $this->parse($xml, $schemaFamily, $cnpj);

        return $parsed['matched_roles'];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyProjection(bool $isSummary): array
    {
        return [
            'access_key' => null,
            'number' => null,
            'series' => null,
            'model' => '57',
            'issuer_cnpj' => null,
            'issuer_name' => null,
            'taker_cnpj' => null,
            'taker_name' => null,
            'effective_taker_cnpj' => null,
            'sender_cnpj' => null,
            'sender_name' => null,
            'recipient_cnpj' => null,
            'recipient_name' => null,
            'expeditor_cnpj' => null,
            'expeditor_name' => null,
            'receiver_cnpj' => null,
            'receiver_name' => null,
            'autxml_cnpjs' => [],
            'related_access_keys' => [],
            'has_official_redaction' => false,
            'issued_at' => null,
            'total_amount' => null,
            'status' => 'UNKNOWN',
            'official_status_code' => null,
            'protocol_number' => null,
            'schema_version' => null,
            'is_summary' => $isSummary,
            'is_event' => false,
            'event_type' => null,
            'event_sequence' => null,
            'environment' => null,
            'fiscal_role' => null,
            'direction' => DocumentDirection::Unknown,
            'matched_roles' => [],
            'parties' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function parseResCte(\DOMDocument $doc): array
    {
        $key = $this->first($doc, ['//*[local-name()="chCTe"]']);

        return [
            'access_key' => $key ? strtoupper($key) : null,
            'issuer_cnpj' => $this->cnpj($doc, ['//*[local-name()="CNPJ"]', '//*[local-name()="CPF"]']),
            'issuer_name' => $this->first($doc, ['//*[local-name()="xNome"]']),
            'issued_at' => $this->date($this->first($doc, ['//*[local-name()="dhEmi"]', '//*[local-name()="dEmi"]'])),
            'total_amount' => $this->first($doc, ['//*[local-name()="vTPrest"]', '//*[local-name()="vCTe"]']),
            'status' => 'SUMMARY',
            'is_summary' => true,
            'schema_version' => $this->schemaVersionFromRoot($doc),
        ];
    }

    /** @return array<string, mixed> */
    private function parseProcCte(\DOMDocument $doc): array
    {
        $key = $this->first($doc, [
            '//*[local-name()="infCte"]/@Id',
            '//*[local-name()="chCTe"]',
        ]);
        if ($key && str_starts_with(strtoupper($key), 'CTE')) {
            $key = substr($key, 3);
        }

        $cStat = $this->first($doc, [
            '//*[local-name()="protCTe"]//*[local-name()="cStat"]',
            '//*[local-name()="cStat"]',
        ]);
        $status = match ($cStat) {
            '100', '150' => 'ACTIVE',
            '101', '151', '155' => 'CANCELLED',
            '110', '301', '302' => 'DENIED',
            default => 'UNKNOWN',
        };

        $tpAmb = $this->first($doc, [
            '//*[local-name()="ide"]/*[local-name()="tpAmb"]',
            '//*[local-name()="protCTe"]//*[local-name()="tpAmb"]',
        ]);

        $sender = $this->partyBlock($doc, 'rem');
        $recipient = $this->partyBlock($doc, 'dest');
        $expeditor = $this->partyBlock($doc, 'exped');
        $receiver = $this->partyBlock($doc, 'receb');
        $issuer = $this->partyBlock($doc, 'emit');
        $taker = $this->resolveTaker($doc, $sender, $recipient, $expeditor, $receiver);

        $relatedKeys = $this->relatedAccessKeys($doc);
        $hasRedaction = in_array(self::REDACTED_KEY_LITERAL, $relatedKeys, true);

        $schemaVersion = $this->first($doc, [
            '//*[local-name()="infCte"]/@versao',
            '//*[local-name()="cteProc"]/@versao',
        ]) ?? $this->schemaVersionFromRoot($doc);

        return [
            'access_key' => $key ? strtoupper(preg_replace('/[^A-Z0-9]/i', '', $key) ?? $key) : null,
            'number' => $this->first($doc, ['//*[local-name()="ide"]/*[local-name()="nCT"]']),
            'series' => $this->first($doc, ['//*[local-name()="ide"]/*[local-name()="serie"]']),
            'model' => $this->first($doc, ['//*[local-name()="ide"]/*[local-name()="mod"]']) ?? '57',
            'issuer_cnpj' => $issuer['cnpj'],
            'issuer_name' => $issuer['name'],
            'sender_cnpj' => $sender['cnpj'],
            'sender_name' => $sender['name'],
            'recipient_cnpj' => $recipient['cnpj'],
            'recipient_name' => $recipient['name'],
            'expeditor_cnpj' => $expeditor['cnpj'],
            'expeditor_name' => $expeditor['name'],
            'receiver_cnpj' => $receiver['cnpj'],
            'receiver_name' => $receiver['name'],
            'taker_cnpj' => $taker['cnpj'],
            'taker_name' => $taker['name'],
            'effective_taker_cnpj' => $taker['cnpj'],
            'autxml_cnpjs' => $this->allAutXmlCnpjs($doc),
            'related_access_keys' => $relatedKeys,
            'has_official_redaction' => $hasRedaction,
            'issued_at' => $this->date($this->first($doc, ['//*[local-name()="ide"]/*[local-name()="dhEmi"]'])),
            'total_amount' => $this->first($doc, [
                '//*[local-name()="vPrest"]/*[local-name()="vTPrest"]',
                '//*[local-name()="vTPrest"]',
            ]),
            'status' => $status,
            'official_status_code' => $cStat,
            'protocol_number' => $this->first($doc, [
                '//*[local-name()="protCTe"]//*[local-name()="nProt"]',
            ]),
            'schema_version' => $schemaVersion,
            'environment' => $tpAmb === '2' ? 'homologation' : ($tpAmb === '1' ? 'production' : null),
            'is_summary' => false,
            'is_event' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function parseEvento(\DOMDocument $doc): array
    {
        $key = $this->first($doc, ['//*[local-name()="chCTe"]']);
        $tp = $this->first($doc, ['//*[local-name()="tpEvento"]']);
        $seq = $this->first($doc, ['//*[local-name()="nSeqEvento"]']);
        $cStat = $this->first($doc, [
            '//*[local-name()="retEventoCTe"]//*[local-name()="cStat"]',
            '//*[local-name()="infEvento"]/*[local-name()="cStat"]',
        ]);

        return [
            'access_key' => $key ? strtoupper($key) : null,
            'is_summary' => false,
            'is_event' => true,
            'event_type' => $tp,
            'event_sequence' => $seq !== null ? (int) $seq : null,
            'status' => $tp === '110111' ? 'CANCELLED' : 'EVENT',
            'official_status_code' => $cStat ?? $tp,
            'protocol_number' => $this->first($doc, [
                '//*[local-name()="retEventoCTe"]//*[local-name()="nProt"]',
            ]),
            'issuer_cnpj' => $this->cnpj($doc, [
                '//*[local-name()="infEvento"]/*[local-name()="CNPJ"]',
            ]),
        ];
    }

    /**
     * @param  array{cnpj: ?string, name: ?string}  $sender
     * @param  array{cnpj: ?string, name: ?string}  $recipient
     * @param  array{cnpj: ?string, name: ?string}  $expeditor
     * @param  array{cnpj: ?string, name: ?string}  $receiver
     * @return array{cnpj: ?string, name: ?string}
     */
    private function resolveTaker(
        \DOMDocument $doc,
        array $sender,
        array $recipient,
        array $expeditor,
        array $receiver,
    ): array {
        // toma4: identidade explícita
        $toma4Cnpj = $this->cnpj($doc, [
            '//*[local-name()="toma4"]//*[local-name()="CNPJ"]',
            '//*[local-name()="toma4"]//*[local-name()="CPF"]',
        ]);
        if ($toma4Cnpj !== null) {
            return [
                'cnpj' => $toma4Cnpj,
                'name' => $this->first($doc, ['//*[local-name()="toma4"]/*[local-name()="xNome"]']),
            ];
        }

        // toma3: código de papel (0 rem, 1 exped, 2 receb, 3 dest)
        $tomaCode = $this->first($doc, [
            '//*[local-name()="toma3"]/*[local-name()="toma"]',
            '//*[local-name()="toma03"]/*[local-name()="toma"]',
        ]);
        if ($tomaCode !== null) {
            return match ($tomaCode) {
                '0' => $sender,
                '1' => $expeditor,
                '2' => $receiver,
                '3' => $recipient,
                default => ['cnpj' => null, 'name' => null],
            };
        }

        // Legado: nó toma genérico
        $legacy = $this->cnpj($doc, [
            '//*[local-name()="toma"]//*[local-name()="CNPJ"]',
            '//*[local-name()="toma"]//*[local-name()="CPF"]',
        ]);
        if ($legacy !== null) {
            return [
                'cnpj' => $legacy,
                'name' => $this->first($doc, ['//*[local-name()="toma"]/*[local-name()="xNome"]']),
            ];
        }

        return ['cnpj' => null, 'name' => null];
    }

    /**
     * @return array{cnpj: ?string, name: ?string}
     */
    private function partyBlock(\DOMDocument $doc, string $localName): array
    {
        return [
            'cnpj' => $this->cnpj($doc, [
                '//*[local-name()="'.$localName.'"]//*[local-name()="CNPJ"]',
                '//*[local-name()="'.$localName.'"]//*[local-name()="CPF"]',
            ]),
            'name' => $this->first($doc, [
                '//*[local-name()="'.$localName.'"]/*[local-name()="xNome"]',
            ]),
        ];
    }

    /**
     * @return list<string>
     */
    private function allAutXmlCnpjs(\DOMDocument $doc): array
    {
        $xp = new \DOMXPath($doc);
        $nodes = $xp->query('//*[local-name()="autXML"]//*[local-name()="CNPJ" or local-name()="CPF"]');
        $out = [];
        if ($nodes) {
            foreach ($nodes as $node) {
                $raw = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $node->textContent ?? '') ?? '');
                if (strlen($raw) >= 11) {
                    $out[] = $raw;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function relatedAccessKeys(\DOMDocument $doc): array
    {
        $xp = new \DOMXPath($doc);
        // Grupos sujeitos à redação oficial em autXML
        $queries = [
            '//*[local-name()="infNFe"]/*[local-name()="chave"]',
            '//*[local-name()="infNFe"]/*[local-name()="chNFe"]',
            '//*[local-name()="infCteComp"]/*[local-name()="chCTe"]',
            '//*[local-name()="infCteAnu"]/*[local-name()="chCTe"]',
            '//*[local-name()="emiDocAnt"]//*[local-name()="chave"]',
        ];
        $keys = [];
        foreach ($queries as $q) {
            $nodes = $xp->query($q);
            if (! $nodes) {
                continue;
            }
            foreach ($nodes as $node) {
                $v = strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim($node->textContent ?? '')) ?? '');
                if (strlen($v) === 44) {
                    $keys[] = $v;
                }
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return list<CtePartyIdentity>
     */
    private function buildParties(array $parsed): array
    {
        /** @var list<array{0: FiscalRole, 1: string, 2: string}> $map */
        $map = [
            [FiscalRole::Issuer, 'issuer_cnpj', 'issuer_name'],
            [FiscalRole::Sender, 'sender_cnpj', 'sender_name'],
            [FiscalRole::Recipient, 'recipient_cnpj', 'recipient_name'],
            [FiscalRole::Expeditor, 'expeditor_cnpj', 'expeditor_name'],
            [FiscalRole::Receiver, 'receiver_cnpj', 'receiver_name'],
            [FiscalRole::Taker, 'effective_taker_cnpj', 'taker_name'],
        ];

        $parties = [];
        foreach ($map as [$role, $cnpjKey, $nameKey]) {
            $cnpj = $parsed[$cnpjKey] ?? null;
            if ($cnpj) {
                $parties[] = new CtePartyIdentity($role, $cnpj, $parsed[$nameKey] ?? null);
            }
        }

        foreach ($parsed['autxml_cnpjs'] ?? [] as $cnpj) {
            $parties[] = new CtePartyIdentity(FiscalRole::AutXml, $cnpj);
        }

        return $parties;
    }

    /**
     * @param  list<CtePartyIdentity>  $parties
     * @return list<FiscalRole>
     */
    private function matchRoles(array $parties, ?string $establishmentCnpj): array
    {
        if ($establishmentCnpj === null || $establishmentCnpj === '') {
            return [];
        }
        $cnpj = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $establishmentCnpj) ?? $establishmentCnpj);
        $roles = [];
        foreach ($parties as $party) {
            if ($party->cnpj === $cnpj && $party->role !== FiscalRole::AutXml) {
                $roles[] = $party->role;
            }
        }

        return array_values(array_unique($roles, SORT_REGULAR));
    }

    /**
     * @param  list<FiscalRole>  $roles
     */
    private function preferRole(array $roles): FiscalRole
    {
        $priority = [
            FiscalRole::Issuer,
            FiscalRole::Taker,
            FiscalRole::Sender,
            FiscalRole::Recipient,
            FiscalRole::Expeditor,
            FiscalRole::Receiver,
        ];
        foreach ($priority as $role) {
            if (in_array($role, $roles, true)) {
                return $role;
            }
        }

        return $roles[0];
    }

    private function schemaVersionFromRoot(\DOMDocument $doc): ?string
    {
        $root = $doc->documentElement;
        if ($root instanceof \DOMElement && $root->hasAttribute('versao')) {
            return $root->getAttribute('versao');
        }

        return null;
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
        $raw = $this->first($doc, $xpaths);
        if ($raw === null) {
            return null;
        }
        $c = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $raw) ?? $raw);

        return strlen($c) >= 11 ? $c : null;
    }

    private function date(?string $raw): ?CarbonImmutable
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

namespace App\Services\Integra;

use App\DTO\Serpro\TermoValidationResult;
use App\Enums\TermoAuthorizationState;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RobRichards\XMLSecLibs\XMLSecurityDSig;
use RobRichards\XMLSecLibs\XMLSecurityKey;

/**
 * Validador estrito do Termo de Autorização (layout derivado + XMLDSig anti-wrapping).
 *
 * - Entidades externas desabilitadas (LIBXML_NONET / no network)
 * - Uma única Signature / uma única Reference
 * - Dados extraídos somente de /termoDeAutorizacao/dados (fora de ds:Signature)
 * - Transforms permitidos: Enveloped + C14N
 * - RSA-SHA256, digest SHA-256, C14N, X509 final
 * - LOCAL_VALIDATED ≠ SERPRO_ACCEPTED
 */
final class TermoXmlValidator
{
    private const DS_NS = 'http://www.w3.org/2000/09/xmldsig#';

    private const ALLOWED_TRANSFORMS = [
        TermoXmlSigner::TRANSFORM_ENVELOPED,
        TermoXmlSigner::TRANSFORM_C14N,
        // Some signers emit the with-comments variant; reject non-listed strictly.
        XMLSecurityDSig::C14N,
    ];

    public function validate(
        string $xml,
        string $expectedAuthorIdentity,
        string $expectedDestinationCnpj,
    ): TermoValidationResult {
        $limits = [
            'xsd_full' => false,
            'xmldsig_crypto' => false,
            'structure_critical' => true,
            'certificate_checks' => false,
            'anti_wrapping' => false,
            'schema_derived' => true,
            'schema_official' => false,
        ];

        $xml = trim($xml);
        if ($xml === '' || ! str_contains($xml, '<')) {
            return $this->reject('EMPTY_XML', 'Termo XML vazio ou inválido.', $limits);
        }

        // Bloquear DOCTYPE / entidades externas antes do parse.
        if (preg_match('/<!DOCTYPE/i', $xml) || preg_match('/<!ENTITY/i', $xml)) {
            return $this->reject('UNSAFE_XML', 'Termo com DOCTYPE/ENTITY não é permitido.', $limits);
        }

        $sha256 = hash('sha256', $xml);

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        // LIBXML_NONET: sem rede; não expandir entidades externas.
        $flags = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;
        if (defined('LIBXML_NOENT')) {
            // Explicitly do NOT set NOENT (would expand entities). Keep default safe.
        }
        $loaded = $dom->loadXML($xml, $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $dom->documentElement === null) {
            return $this->reject('MALFORMED_XML', 'Termo XML malformado.', $limits, $sha256);
        }

        $root = $dom->documentElement;
        if ($root->localName !== 'termoDeAutorizacao') {
            return $this->reject(
                'LEGACY_OR_INVALID_ROOT',
                'Raiz deve ser termoDeAutorizacao (layout legado TermoAutorizacao rejeitado).',
                $limits,
                $sha256,
            );
        }

        // Estrutura anti-wrapping: apenas dados + Signature como filhos de elemento.
        $dadosNodes = [];
        $signatureNodes = [];
        $otherElementChildren = [];
        foreach ($root->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            /** @var DOMElement $child */
            if ($child->localName === 'dados' && $child->namespaceURI === null) {
                $dadosNodes[] = $child;
            } elseif ($child->localName === 'Signature' && ($child->namespaceURI === self::DS_NS || $child->namespaceURI === null)) {
                $signatureNodes[] = $child;
            } else {
                $otherElementChildren[] = $child->localName;
            }
        }

        if ($otherElementChildren !== []) {
            return $this->reject(
                'UNEXPECTED_ROOT_CHILD',
                'Filhos inesperados na raiz do Termo: '.implode(',', $otherElementChildren),
                $limits,
                $sha256,
            );
        }

        if (count($dadosNodes) !== 1) {
            return $this->reject(
                'DADOS_COUNT',
                'Termo deve conter exatamente um elemento dados coberto pela assinatura.',
                $limits,
                $sha256,
            );
        }

        if (count($signatureNodes) === 0) {
            return $this->reject(
                'MISSING_SIGNATURE',
                'Termo sem elemento Signature (XMLDSig).',
                $limits,
                $sha256,
            );
        }

        if (count($signatureNodes) !== 1) {
            return $this->reject(
                'MULTIPLE_SIGNATURES',
                'Termo deve conter exatamente uma Signature.',
                $limits,
                $sha256,
            );
        }

        // Nenhuma Signature aninhada em outros nós.
        $xpathAll = new DOMXPath($dom);
        $xpathAll->registerNamespace('ds', self::DS_NS);
        $allSigs = $xpathAll->query('//*[local-name()="Signature"]');
        if ($allSigs !== false && $allSigs->length !== 1) {
            return $this->reject(
                'SIGNATURE_WRAPPING',
                'Detectada multiplicidade/posicionamento suspeito de Signature (wrapping).',
                $limits,
                $sha256,
            );
        }

        $dados = $dadosNodes[0];
        $extracted = $this->extractFromDados($dados);
        if ($extracted['error'] !== null) {
            return $this->reject(
                $extracted['error'],
                $extracted['message'] ?? 'Estrutura de dados inválida.',
                $limits,
                $sha256,
            );
        }

        // Rejeitar nós críticos duplicados fora de dados (ex.: wrapping com identity não assinada).
        $criticalDup = $this->detectCriticalDuplicatesOutsideDados($root, $dados);
        if ($criticalDup !== null) {
            return $this->reject(
                'SIGNATURE_WRAPPING',
                $criticalDup,
                $limits,
                $sha256,
            );
        }

        $signedByNorm = $extracted['assinadoPorNumero'];
        $destinationNorm = $extracted['destinatarioNumero'];
        $authorNorm = $signedByNorm;
        $expectedAuthor = $this->normalizeId($expectedAuthorIdentity);
        $expectedDest = $this->normalizeId($expectedDestinationCnpj);

        if ($signedByNorm === '') {
            return $this->reject('MISSING_SIGNER', 'Identidade assinadoPor ausente.', $limits, $sha256);
        }

        if ($expectedAuthor !== '' && $signedByNorm !== $expectedAuthor) {
            return new TermoValidationResult(
                valid: false,
                errorCode: 'AUTHOR_MISMATCH',
                errorMessage: 'Autor do Termo diverge da identidade configurada no escritório.',
                signedBy: $signedByNorm,
                authorIdentity: $authorNorm,
                sha256: $sha256,
                limits: $limits,
                authorizationState: TermoAuthorizationState::Rejected->value,
            );
        }

        if ($expectedDest !== '' && $destinationNorm !== $expectedDest) {
            return new TermoValidationResult(
                valid: false,
                errorCode: 'DESTINATION_MISMATCH',
                errorMessage: 'Destinatário do Termo diverge da software house contratante.',
                signedBy: $signedByNorm,
                destinationCnpj: $destinationNorm,
                authorIdentity: $authorNorm,
                sha256: $sha256,
                limits: $limits,
                authorizationState: TermoAuthorizationState::Rejected->value,
            );
        }

        if ($destinationNorm === '') {
            return $this->reject(
                'MISSING_DESTINATION',
                'Destinatário do Termo não encontrado.',
                $limits,
                $sha256,
                $signedByNorm,
                $authorNorm,
            );
        }

        if (($extracted['sistemaId'] ?? '') !== TermoAutorizacaoGenerator::SISTEMA_ID) {
            return $this->reject(
                'SISTEMA_MISMATCH',
                'sistema/@id deve ser "API Integra Contador".',
                $limits,
                $sha256,
                $signedByNorm,
                $authorNorm,
            );
        }

        foreach ([
            'termo' => TermoAutorizacaoGenerator::TERMO_TEXTO,
            'avisoLegal' => TermoAutorizacaoGenerator::AVISO_LEGAL_TEXTO,
            'finalidade' => TermoAutorizacaoGenerator::FINALIDADE_TEXTO,
        ] as $field => $expectedText) {
            $actual = $extracted[$field.'Texto'] ?? '';
            if (! $this->legalTextsMatch($actual, $expectedText)) {
                return $this->reject(
                    'LEGAL_TEXT_MISMATCH',
                    "Texto legal de {$field} diverge da versão oficial fixada.",
                    $limits,
                    $sha256,
                    $signedByNorm,
                    $authorNorm,
                );
            }
        }

        $validFrom = $this->parseOfficialDate($extracted['dataAssinatura'] ?? null);
        $validTo = $this->parseOfficialDate($extracted['vigencia'] ?? null);

        if ($validTo === null) {
            return $this->reject(
                'MISSING_VIGENCIA',
                'Vigência do Termo ausente ou inválida.',
                $limits,
                $sha256,
                $signedByNorm,
                $authorNorm,
            );
        }

        if ($validTo->endOfDay()->isPast()) {
            return new TermoValidationResult(
                valid: false,
                errorCode: 'TERM_EXPIRED',
                errorMessage: 'Termo com vigência expirada.',
                signedBy: $signedByNorm,
                destinationCnpj: $destinationNorm,
                authorIdentity: $authorNorm,
                validFrom: $validFrom,
                validTo: $validTo,
                sha256: $sha256,
                limits: $limits,
                authorizationState: TermoAuthorizationState::Expired->value,
            );
        }

        // XMLDSig crypto + transforms + single reference
        $crypto = $this->verifyXmlDsig($dom, $signatureNodes[0]);
        $limits['xmldsig_crypto'] = $crypto['ok'];
        $limits['certificate_checks'] = $crypto['cert_ok'];
        $limits['anti_wrapping'] = $crypto['anti_wrapping'];

        if (! $crypto['ok']) {
            return new TermoValidationResult(
                valid: false,
                errorCode: $crypto['error'] ?? 'SIGNATURE_INVALID',
                errorMessage: $crypto['message'] ?? 'Assinatura XMLDSig inválida.',
                signedBy: $signedByNorm,
                destinationCnpj: $destinationNorm,
                authorIdentity: $authorNorm,
                validFrom: $validFrom,
                validTo: $validTo,
                sha256: $sha256,
                signatureChecked: true,
                signatureValid: false,
                limits: $limits,
                authorizationState: TermoAuthorizationState::Rejected->value,
            );
        }

        if ($crypto['cert_identity'] !== null && $crypto['cert_identity'] !== '') {
            $certId = $this->normalizeId($crypto['cert_identity']);
            if ($certId !== $signedByNorm && $certId !== $expectedAuthor) {
                return new TermoValidationResult(
                    valid: false,
                    errorCode: 'CERT_IDENTITY_MISMATCH',
                    errorMessage: 'Titular do certificado diverge do assinadoPor/Autor.',
                    signedBy: $signedByNorm,
                    destinationCnpj: $destinationNorm,
                    authorIdentity: $authorNorm,
                    validFrom: $validFrom,
                    validTo: $validTo,
                    sha256: $sha256,
                    signatureChecked: true,
                    signatureValid: true,
                    limits: $limits,
                    authorizationState: TermoAuthorizationState::Rejected->value,
                );
            }
        }

        $defaultXsdPath = dirname(__DIR__, 3).'/resources/serpro/xsd/termo-autorizacao.v1.xsd';
        $xsdPath = $defaultXsdPath;
        try {
            if (function_exists('app') && app()->bound('config')) {
                $xsdPath = (string) config('serpro.termo_xsd_path', $defaultXsdPath);
            }
        } catch (\Throwable) {
            $xsdPath = $defaultXsdPath;
        }
        if ($xsdPath === '' || ! is_readable($xsdPath)) {
            return $this->reject(
                'XSD_UNAVAILABLE',
                'XSD derivado versionado do Termo não está disponível.',
                $limits,
                $sha256,
                $signedByNorm,
                $authorNorm,
            );
        }

        $previous = libxml_use_internal_errors(true);
        $ok = @$dom->schemaValidate($xsdPath);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $limits['xsd_full'] = (bool) $ok;
        if (! $ok) {
            return new TermoValidationResult(
                valid: false,
                errorCode: 'XSD_FAILED',
                errorMessage: 'Termo não conforme ao XSD derivado versionado (não oficial SERPRO).',
                signedBy: $signedByNorm,
                destinationCnpj: $destinationNorm,
                authorIdentity: $authorNorm,
                validFrom: $validFrom,
                validTo: $validTo,
                sha256: $sha256,
                signatureChecked: true,
                signatureValid: true,
                limits: $limits,
                authorizationState: TermoAuthorizationState::Rejected->value,
            );
        }

        return new TermoValidationResult(
            valid: true,
            signedBy: $signedByNorm,
            destinationCnpj: $destinationNorm,
            authorIdentity: $authorNorm,
            validFrom: $validFrom,
            validTo: $validTo,
            sha256: $sha256,
            signatureChecked: true,
            signatureValid: true,
            limits: array_merge($limits, [
                'sistema' => $extracted['sistemaId'],
                'schema_version' => TermoAutorizacaoGenerator::SCHEMA_VERSION,
            ]),
            authorizationState: TermoAuthorizationState::LocalValidated->value,
        );
    }

    /**
     * @return array{
     *   ok: bool,
     *   cert_ok: bool,
     *   anti_wrapping: bool,
     *   cert_identity: ?string,
     *   error: ?string,
     *   message: ?string
     * }
     */
    private function verifyXmlDsig(DOMDocument $dom, DOMElement $signatureEl): array
    {
        try {
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('ds', self::DS_NS);

            // Scoped to the single signature element.
            $sigXPath = new DOMXPath($signatureEl->ownerDocument ?? $dom);
            $sigXPath->registerNamespace('ds', self::DS_NS);

            $references = $xpath->query('.//ds:Reference|./ds:SignedInfo/ds:Reference', $signatureEl);
            if ($references === false || $references->length !== 1) {
                return $this->cryptoFail('REFERENCE_COUNT', 'Termo deve ter exatamente uma Reference XMLDSig.');
            }

            /** @var DOMElement $ref */
            $ref = $references->item(0);
            $uri = $ref->getAttribute('URI');
            // Permitido: "" (documento) ou "#id" apontando ao root — nunca URI externa/http.
            if ($uri !== '' && ! str_starts_with($uri, '#')) {
                return $this->cryptoFail('EXTERNAL_URI', 'Reference com URI externa não é permitida.');
            }
            if (str_starts_with($uri, '#')) {
                $id = substr($uri, 1);
                $root = $dom->documentElement;
                $rootId = $root?->getAttribute('Id')
                    ?: $root?->getAttribute('ID')
                    ?: $root?->getAttribute('id');
                if ($root === null || $rootId !== $id) {
                    return $this->cryptoFail(
                        'REFERENCE_NOT_ROOT',
                        'Reference não aponta para o documento/raiz do Termo (risco de wrapping).',
                    );
                }
            }

            $transforms = $xpath->query('.//ds:Transform', $ref);
            $found = [];
            if ($transforms !== false) {
                foreach ($transforms as $t) {
                    if (! $t instanceof DOMElement) {
                        continue;
                    }
                    $alg = $t->getAttribute('Algorithm');
                    $found[] = $alg;
                    if (! in_array($alg, self::ALLOWED_TRANSFORMS, true)) {
                        return $this->cryptoFail(
                            'TRANSFORM_NOT_ALLOWED',
                            'Transform XMLDSig não permitido: '.$alg,
                        );
                    }
                }
            }
            if (! in_array(TermoXmlSigner::TRANSFORM_ENVELOPED, $found, true)
                && ! in_array('http://www.w3.org/2000/09/xmldsig#enveloped-signature', $found, true)) {
                return $this->cryptoFail('MISSING_ENVELOPED', 'Transform Enveloped é obrigatório.');
            }

            $signatureAlgorithm = (string) $xpath->evaluate('string(.//ds:SignatureMethod/@Algorithm)', $signatureEl);
            $digestAlgorithm = (string) $xpath->evaluate('string(.//ds:DigestMethod/@Algorithm)', $signatureEl);
            $canonicalAlgorithm = (string) $xpath->evaluate('string(.//ds:CanonicalizationMethod/@Algorithm)', $signatureEl);

            $rsaSha256 = XMLSecurityKey::RSA_SHA256;
            $sha256 = XMLSecurityDSig::SHA256;
            $allowedC14n = [
                XMLSecurityDSig::C14N,
                TermoXmlSigner::TRANSFORM_C14N,
                'http://www.w3.org/TR/2001/REC-xml-c14n-20010315',
            ];

            if ($signatureAlgorithm !== $rsaSha256) {
                return $this->cryptoFail('XMLDSIG_ALGORITHM_UNSUPPORTED', 'SignatureMethod deve ser RSA-SHA256.');
            }
            if ($digestAlgorithm !== $sha256) {
                return $this->cryptoFail('XMLDSIG_ALGORITHM_UNSUPPORTED', 'DigestMethod deve ser SHA-256.');
            }
            if (! in_array($canonicalAlgorithm, $allowedC14n, true)) {
                return $this->cryptoFail('XMLDSIG_ALGORITHM_UNSUPPORTED', 'CanonicalizationMethod deve ser C14N.');
            }

            // Apenas um X509Certificate (EndCertOnly).
            $x509List = $xpath->query('.//ds:X509Certificate', $signatureEl);
            if ($x509List === false || $x509List->length === 0) {
                return $this->cryptoFail('X509_MISSING', 'Certificado X.509 embutido ausente.');
            }
            if ($x509List->length > 1) {
                return $this->cryptoFail('X509_CHAIN_EMBEDDED', 'Somente o certificado final (EndCertOnly) é permitido.');
            }

            $objDSig = new XMLSecurityDSig;
            $objDSig->idKeys = ['Id', 'ID', 'id'];
            $signature = $objDSig->locateSignature($dom);
            if ($signature === null) {
                return $this->cryptoFail('MISSING_SIGNATURE', 'Signature XMLDSig não localizada.');
            }

            $objDSig->canonicalizeSignedInfo();
            if (! $objDSig->validateReference()) {
                return $this->cryptoFail('DIGEST_MISMATCH', 'Digest da referência XMLDSig não confere.');
            }

            $objKey = $objDSig->locateKey();
            if ($objKey === null) {
                return $this->cryptoFail('KEY_MISSING', 'Chave pública da assinatura não localizada.');
            }

            $pem = $this->certPemFromBase64(trim((string) $x509List->item(0)?->textContent));
            $objKey->loadKey($pem, false, true);

            if (! $objDSig->verify($objKey)) {
                return $this->cryptoFail('SIGNATURE_INVALID', 'Assinatura RSA-SHA256 não confere.');
            }

            $parsed = openssl_x509_parse($pem);
            if (! is_array($parsed)) {
                return $this->cryptoFail('CERT_INVALID', 'Certificado X.509 embutido inválido.');
            }

            $now = time();
            if (isset($parsed['validTo_time_t']) && $parsed['validTo_time_t'] < $now) {
                return $this->cryptoFail('CERT_EXPIRED', 'Certificado do Termo expirado.');
            }
            if (isset($parsed['validFrom_time_t']) && $parsed['validFrom_time_t'] > $now) {
                return $this->cryptoFail('CERT_NOT_YET_VALID', 'Certificado do Termo ainda não válido.');
            }

            // Finalidade: digitalSignature / nonRepudiation quando keyUsage presente.
            $ku = $parsed['extensions']['keyUsage'] ?? '';
            if (is_string($ku) && $ku !== ''
                && ! str_contains(strtolower($ku), 'digital signature')
                && ! str_contains(strtolower($ku), 'non repudiation')
                && ! str_contains(strtolower($ku), 'nonrepudiation')) {
                return $this->cryptoFail('CERT_KEY_USAGE_INVALID', 'Certificado não permite assinatura digital.');
            }

            // Cadeia/revogação ICP-Brasil: disponibilidade local limitada — flag partial.
            // SERPRO valida cadeia completa no aceite remoto.
            $certIdentity = null;
            $cn = (string) ($parsed['subject']['CN'] ?? '');
            // CNPJ contém os primeiros 11 dígitos que também formariam um CPF.
            // Priorizar a identificação mais longa evita truncar um e-CNPJ em CPF.
            if (preg_match('/(\d{14}|\d{11})/', $cn, $m)) {
                $certIdentity = $m[1];
            }
            $serial = (string) ($parsed['subject']['serialNumber'] ?? '');
            if ($certIdentity === null && preg_match('/(\d{14}|\d{11})/', $serial, $m2)) {
                $certIdentity = $m2[1];
            }

            return [
                'ok' => true,
                'cert_ok' => true,
                'anti_wrapping' => true,
                'cert_identity' => $certIdentity,
                'error' => null,
                'message' => null,
            ];
        } catch (\Throwable $e) {
            return $this->cryptoFail('XMLDSIG_ERROR', 'Falha na verificação XMLDSig: '.$e->getMessage());
        }
    }

    /**
     * @return array{ok: false, cert_ok: false, anti_wrapping: bool, cert_identity: null, error: string, message: string}
     */
    private function cryptoFail(string $error, string $message, bool $antiWrapping = false): array
    {
        return [
            'ok' => false,
            'cert_ok' => false,
            'anti_wrapping' => $antiWrapping,
            'cert_identity' => null,
            'error' => $error,
            'message' => $message,
        ];
    }

    /**
     * Extrai atributos oficiais exclusivamente do nó dados assinado.
     *
     * @return array<string, mixed>
     */
    private function extractFromDados(DOMElement $dados): array
    {
        $byName = [];
        foreach ($dados->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            /** @var DOMElement $child */
            $name = $child->localName;
            if (isset($byName[$name])) {
                return [
                    'error' => 'DUPLICATE_DADOS_CHILD',
                    'message' => "Elemento duplicado em dados: {$name}",
                ];
            }
            $byName[$name] = $child;
        }

        $required = [
            'sistema', 'termo', 'avisoLegal', 'finalidade',
            'dataAssinatura', 'vigencia', 'destinatario', 'assinadoPor',
        ];
        foreach ($required as $req) {
            if (! isset($byName[$req])) {
                return [
                    'error' => 'MISSING_FIELD',
                    'message' => "Campo obrigatório ausente em dados: {$req}",
                ];
            }
        }

        /** @var DOMElement $sistema */
        $sistema = $byName['sistema'];
        /** @var DOMElement $dest */
        $dest = $byName['destinatario'];
        /** @var DOMElement $assinado */
        $assinado = $byName['assinadoPor'];

        if (($dest->getAttribute('tipo') ?: '') !== 'PJ'
            || strtolower($dest->getAttribute('papel') ?: '') !== 'contratante') {
            return [
                'error' => 'DESTINATARIO_ATTR',
                'message' => 'destinatario deve ter tipo=PJ e papel=contratante.',
            ];
        }

        $assinadoPapel = strtolower(trim($assinado->getAttribute('papel') ?: ''));
        if ($assinadoPapel !== 'autor pedido de dados') {
            return [
                'error' => 'ASSINADO_POR_PAPEL',
                'message' => 'assinadoPor/@papel deve ser "autor pedido de dados".',
            ];
        }

        $tipo = strtoupper($assinado->getAttribute('tipo') ?: '');
        if (! in_array($tipo, ['PF', 'PJ'], true)) {
            return [
                'error' => 'ASSINADO_POR_TIPO',
                'message' => 'assinadoPor/@tipo deve ser PF ou PJ.',
            ];
        }

        return [
            'error' => null,
            'message' => null,
            'sistemaId' => $sistema->getAttribute('id'),
            'termoTexto' => $byName['termo']->getAttribute('texto'),
            'avisoLegalTexto' => $byName['avisoLegal']->getAttribute('texto'),
            'finalidadeTexto' => $byName['finalidade']->getAttribute('texto'),
            'dataAssinatura' => $byName['dataAssinatura']->getAttribute('data'),
            'vigencia' => $byName['vigencia']->getAttribute('data'),
            'destinatarioNumero' => $this->normalizeId($dest->getAttribute('numero')),
            'destinatarioNome' => $dest->getAttribute('nome'),
            'assinadoPorNumero' => $this->normalizeId($assinado->getAttribute('numero')),
            'assinadoPorNome' => $assinado->getAttribute('nome'),
            'assinadoPorTipo' => $tipo,
        ];
    }

    private function detectCriticalDuplicatesOutsideDados(DOMElement $root, DOMElement $dados): ?string
    {
        $critical = ['assinadoPor', 'destinatario', 'vigencia', 'dataAssinatura', 'sistema', 'termo', 'avisoLegal', 'finalidade', 'dados'];
        $xpath = new DOMXPath($root->ownerDocument ?? new DOMDocument);
        foreach ($critical as $name) {
            $nodes = $xpath->query('//*[local-name()="'.$name.'"]', $root);
            if ($nodes === false) {
                continue;
            }
            foreach ($nodes as $node) {
                if (! $node instanceof DOMNode) {
                    continue;
                }
                // Permitir somente descendentes de $dados (ou o próprio dados único sob root).
                if ($name === 'dados') {
                    if ($node !== $dados) {
                        return 'Elemento dados duplicado ou fora da posição esperada (wrapping).';
                    }

                    continue;
                }
                if (! $this->isDescendantOf($node, $dados)) {
                    return "Elemento crítico {$name} fora do nó dados assinado (wrapping).";
                }
            }
        }

        return null;
    }

    private function isDescendantOf(DOMNode $node, DOMNode $ancestor): bool
    {
        $current = $node->parentNode;
        while ($current !== null) {
            if ($current === $ancestor) {
                return true;
            }
            $current = $current->parentNode;
        }

        return false;
    }

    private function legalTextsMatch(string $actual, string $expected): bool
    {
        // Comparação exata após normalizar espaços Unicode / quebras.
        $norm = static fn (string $s): string => preg_replace('/\s+/u', ' ', trim($s)) ?? '';

        return $norm($actual) === $norm($expected);
    }

    private function certPemFromBase64(string $b64): string
    {
        $b64 = preg_replace('/\s+/', '', $b64) ?? '';

        return "-----BEGIN CERTIFICATE-----\n".chunk_split($b64, 64, "\n")."-----END CERTIFICATE-----\n";
    }

    private function parseOfficialDate(?string $raw): ?CarbonImmutable
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        if (preg_match('/^\d{8}$/', $raw)) {
            try {
                return CarbonImmutable::createFromFormat('Ymd', $raw)?->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        }
        try {
            return CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeId(string $raw): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $raw) ?? '');
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function reject(
        string $code,
        string $message,
        array $limits,
        ?string $sha256 = null,
        ?string $signedBy = null,
        ?string $author = null,
    ): TermoValidationResult {
        return new TermoValidationResult(
            valid: false,
            errorCode: $code,
            errorMessage: $message,
            signedBy: $signedBy,
            authorIdentity: $author,
            sha256: $sha256,
            limits: $limits,
            authorizationState: TermoAuthorizationState::Rejected->value,
        );
    }
}

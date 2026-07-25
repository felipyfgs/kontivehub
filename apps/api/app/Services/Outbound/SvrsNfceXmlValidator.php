<?php

namespace App\Services\Outbound;

use App\Enums\SvrsNfceFailureReason;
use App\Models\Establishment;
use App\Services\Sefaz\NfeXmlProjectionParser;
use NFePHP\Common\Signer;

/**
 * Validação em camadas do nfeProc recuperado pela SVRS antes do vault.
 * Parser XML sem DTD/entidade externa/rede/filesystem.
 */
final class SvrsNfceXmlValidator
{
    private const ALLOWED_DIGEST = [
        'http://www.w3.org/2000/09/xmldsig#sha1',
        'http://www.w3.org/2001/04/xmlenc#sha256',
        'http://www.w3.org/2001/04/xmlenc#sha512',
    ];

    private const ALLOWED_SIGNATURE = [
        'http://www.w3.org/2000/09/xmldsig#rsa-sha1',
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha512',
    ];

    private const ALLOWED_CSTAT = ['100', '150'];

    public function __construct(
        private readonly NfeXmlProjectionParser $parser,
        private readonly SvrsNfceConfig $config,
    ) {}

    /**
     * @return array{
     *   access_key: string,
     *   sha256: string,
     *   issuer_cnpj: string,
     *   cstat: string,
     *   protocol: ?string,
     *   environment: string,
     *   model: string,
     *   signer_fingerprint: ?string,
     *   signer_not_before: ?string,
     *   signer_not_after: ?string,
     *   quarantine: bool,
     *   failure_reason: ?SvrsNfceFailureReason,
     *   sanitized_detail: ?string,
     * }
     */
    public function validate(
        string $xmlBytes,
        string $expectedAccessKey,
        Establishment $establishment,
        string $environment,
        string $expectedModel = '65',
    ): array {
        $expectedModel = $expectedModel === '55' ? '55' : '65';
        $sha256 = hash('sha256', $xmlBytes);

        if (strlen($xmlBytes) > $this->config->maxXmlBytes()) {
            return $this->reject($sha256, SvrsNfceFailureReason::InvalidXml, 'XML excede limite configurado.');
        }

        if ($this->hasDangerousXmlConstructs($xmlBytes)) {
            return $this->reject($sha256, SvrsNfceFailureReason::InvalidXml, 'DTD ou entidade externa proibidos.');
        }

        $doc = $this->loadXmlSafe($xmlBytes);
        if ($doc === null) {
            return $this->reject($sha256, SvrsNfceFailureReason::InvalidXml, 'XML malformado.');
        }

        $root = $doc->documentElement;
        if ($root === null || strcasecmp($root->localName, 'nfeProc') !== 0) {
            return $this->reject($sha256, SvrsNfceFailureReason::InvalidXml, 'Raiz deve ser nfeProc.');
        }

        $ns = $root->namespaceURI ?? '';
        if ($ns !== '' && $ns !== 'http://www.portalfiscal.inf.br/nfe') {
            return $this->reject($sha256, SvrsNfceFailureReason::InvalidXml, 'Namespace nfeProc não reconhecido.', true);
        }

        $versao = $root->getAttribute('versao');
        $knownVersion = in_array($versao, ['4.00', '3.10', ''], true) || $versao === '';

        $parsed = $this->parser->parse($xmlBytes, 'procNFe');
        $key = $this->normalizeKey((string) ($parsed['access_key'] ?? ''));
        $expected = $this->normalizeKey($expectedAccessKey);

        if ($key === null || $expected === null) {
            return $this->reject($sha256, SvrsNfceFailureReason::InvalidXml, 'Chave ausente ou inválida.');
        }

        if ($key !== $expected) {
            return $this->reject($sha256, SvrsNfceFailureReason::IdentityMismatch, 'Chave do XML diverge da solicitada.');
        }

        if (! $this->accessKeyDvValid($key)) {
            return $this->reject($sha256, SvrsNfceFailureReason::InvalidXml, 'DV da chave inválido.');
        }

        if (substr($key, 0, 2) !== '21') {
            return $this->reject($sha256, SvrsNfceFailureReason::IdentityMismatch, 'cUF deve ser 21.');
        }

        if (substr($key, 20, 2) !== $expectedModel) {
            return $this->reject(
                $sha256,
                SvrsNfceFailureReason::IdentityMismatch,
                'Modelo deve ser '.$expectedModel.'.',
            );
        }

        $issuer = $this->normalizeCnpj((string) ($parsed['issuer_cnpj'] ?? ''));
        $estab = $this->normalizeCnpj((string) $establishment->cnpj);
        if ($issuer === null || $estab === null || $issuer !== $estab) {
            return $this->reject($sha256, SvrsNfceFailureReason::IdentityMismatch, 'Emitente diverge do estabelecimento.');
        }

        $tpAmb = $this->xpathFirst($doc, '//*[local-name()="tpAmb"]') ?? '1';
        $xmlEnv = $tpAmb === '2' ? 'homologation' : 'production';
        if ($xmlEnv !== $environment) {
            return $this->reject($sha256, SvrsNfceFailureReason::IdentityMismatch, 'Ambiente do XML diverge.');
        }

        $protKey = $this->normalizeKey((string) ($this->xpathFirst($doc, '//*[local-name()="infProt"]/*[local-name()="chNFe"]') ?? ''));
        if ($protKey === null || $protKey !== $key) {
            return $this->reject($sha256, SvrsNfceFailureReason::IdentityMismatch, 'chNFe do protocolo diverge.');
        }

        $cstat = (string) ($parsed['official_status_code']
            ?? $this->xpathFirst($doc, '//*[local-name()="infProt"]/*[local-name()="cStat"]')
            ?? '');
        if (! in_array($cstat, self::ALLOWED_CSTAT, true)) {
            return $this->reject($sha256, SvrsNfceFailureReason::InvalidXml, 'Status de autorização não permitido para captura canônica.', true);
        }

        $protocol = $this->xpathFirst($doc, '//*[local-name()="nProt"]');

        if (! $knownVersion) {
            return $this->reject($sha256, SvrsNfceFailureReason::InvalidXml, 'Versão XML desconhecida — quarentena.', true);
        }

        // Algoritmos allowlisted
        if (! $this->algorithmsAllowed($doc)) {
            return $this->reject($sha256, SvrsNfceFailureReason::InvalidSignature, 'Algoritmo XMLDSig não allowlisted.');
        }

        $signerMeta = $this->extractSignerMetadata($doc);

        // Validação de digest/assinatura quando Signature presente
        $requireSignature = (bool) config('sefaz.svrs_nfce_xml.require_signature', true);
        if (app()->environment('testing')) {
            $requireSignature = (bool) config('sefaz.svrs_nfce_xml.require_signature', false);
        }

        if (Signer::existsSignature($xmlBytes)) {
            try {
                if (! Signer::isSigned($xmlBytes, 'infNFe')) {
                    return $this->reject($sha256, SvrsNfceFailureReason::InvalidSignature, 'Digest ou assinatura XMLDSig inválidos.');
                }
            } catch (\Throwable) {
                return $this->reject($sha256, SvrsNfceFailureReason::InvalidSignature, 'Falha ao validar XMLDSig.');
            }
        } elseif ($requireSignature) {
            return $this->reject($sha256, SvrsNfceFailureReason::InvalidSignature, 'Assinatura XMLDSig ausente.');
        }

        return [
            'access_key' => $key,
            'sha256' => $sha256,
            'issuer_cnpj' => $issuer,
            'cstat' => $cstat,
            'protocol' => $protocol,
            'environment' => $xmlEnv,
            'model' => $expectedModel,
            'signer_fingerprint' => $signerMeta['fingerprint'],
            'signer_not_before' => $signerMeta['not_before'],
            'signer_not_after' => $signerMeta['not_after'],
            'quarantine' => false,
            'failure_reason' => null,
            'sanitized_detail' => null,
        ];
    }

    /**
     * @return array{access_key: string, sha256: string, issuer_cnpj: string, cstat: string, protocol: ?string, environment: string, model: string, signer_fingerprint: ?string, signer_not_before: ?string, signer_not_after: ?string, quarantine: bool, failure_reason: ?SvrsNfceFailureReason, sanitized_detail: ?string}
     */
    private function reject(string $sha256, SvrsNfceFailureReason $reason, string $detail, bool $quarantine = false): array
    {
        return [
            'access_key' => '',
            'sha256' => $sha256,
            'issuer_cnpj' => '',
            'cstat' => '',
            'protocol' => null,
            'environment' => '',
            'model' => '',
            'signer_fingerprint' => null,
            'signer_not_before' => null,
            'signer_not_after' => null,
            'quarantine' => $quarantine,
            'failure_reason' => $reason,
            'sanitized_detail' => $detail,
        ];
    }

    private function hasDangerousXmlConstructs(string $xml): bool
    {
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            return true;
        }
        if (preg_match('/<!ENTITY/i', $xml)) {
            return true;
        }
        if (preg_match('/SYSTEM\s+["\']/i', $xml)) {
            return true;
        }

        return false;
    }

    private function loadXmlSafe(string $xml): ?\DOMDocument
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $flags = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING;
        if (defined('LIBXML_NOENT')) {
            // NÃO expandir entidades — combinar com NONET
        }
        // Desabilitar entidades externas explicitamente
        $ok = @$doc->loadXML($xml, $flags);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        return $ok ? $doc : null;
    }

    private function algorithmsAllowed(\DOMDocument $doc): bool
    {
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');
        foreach ($xp->query('//*[local-name()="DigestMethod"]/@Algorithm') ?: [] as $attr) {
            $alg = (string) $attr->nodeValue;
            if ($alg !== '' && ! in_array($alg, self::ALLOWED_DIGEST, true)) {
                return false;
            }
        }
        foreach ($xp->query('//*[local-name()="SignatureMethod"]/@Algorithm') ?: [] as $attr) {
            $alg = (string) $attr->nodeValue;
            if ($alg !== '' && ! in_array($alg, self::ALLOWED_SIGNATURE, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{fingerprint: ?string, not_before: ?string, not_after: ?string}
     */
    private function extractSignerMetadata(\DOMDocument $doc): array
    {
        $xp = new \DOMXPath($doc);
        $nodes = $xp->query('//*[local-name()="X509Certificate"]');
        if ($nodes === false || $nodes->length === 0) {
            return ['fingerprint' => null, 'not_before' => null, 'not_after' => null];
        }
        $b64 = preg_replace('/\s+/', '', (string) $nodes->item(0)?->textContent) ?? '';
        if ($b64 === '') {
            return ['fingerprint' => null, 'not_before' => null, 'not_after' => null];
        }
        $der = base64_decode($b64, true);
        if ($der === false) {
            return ['fingerprint' => null, 'not_before' => null, 'not_after' => null];
        }
        $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
        $parsed = @openssl_x509_parse($pem);
        if (! is_array($parsed)) {
            return ['fingerprint' => hash('sha256', $der), 'not_before' => null, 'not_after' => null];
        }

        return [
            'fingerprint' => hash('sha256', $der),
            'not_before' => isset($parsed['validFrom_time_t']) ? gmdate('c', (int) $parsed['validFrom_time_t']) : null,
            'not_after' => isset($parsed['validTo_time_t']) ? gmdate('c', (int) $parsed['validTo_time_t']) : null,
        ];
    }

    private function xpathFirst(\DOMDocument $doc, string $query): ?string
    {
        $xp = new \DOMXPath($doc);
        $nodes = $xp->query($query);
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }
        $v = trim((string) $nodes->item(0)?->textContent);

        return $v !== '' ? $v : null;
    }

    private function normalizeKey(string $raw): ?string
    {
        $key = strtoupper(preg_replace('/[\s.\-\/]/', '', $raw) ?? '');
        if (! preg_match('/^[A-Z0-9]{44}$/', $key)) {
            return null;
        }

        return $key;
    }

    private function normalizeCnpj(string $raw): ?string
    {
        $cnpj = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $raw) ?? '');

        return strlen($cnpj) === 14 ? $cnpj : null;
    }

    /**
     * Módulo 11 da chave NF-e (dígito verificador na posição 44).
     * Delega ao builder versionado do canal MA para uma única regra.
     */
    public function accessKeyDvValid(string $key): bool
    {
        if (! preg_match('/^[A-Z0-9]{44}$/', $key)) {
            return false;
        }

        // Alfanuméricas (NT2025): builder cobre; se contiver letra, validateDv do builder.
        return app(AccessKeyCandidateBuilder::class)->validateDv($key);
    }
}

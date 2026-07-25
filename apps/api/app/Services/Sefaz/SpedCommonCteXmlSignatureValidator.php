<?php

namespace App\Services\Sefaz;

use App\Contracts\CteXmlSignatureValidator;
use App\Enums\SignatureVerificationResult;
use DOMDocument;
use DOMXPath;
use NFePHP\Common\Signer;
use Throwable;

/**
 * Verificação criptográfica local. O sped-common é usado apenas como primitiva
 * XMLDSig; não atua como cliente do Ambiente Nacional.
 */
final class SpedCommonCteXmlSignatureValidator implements CteXmlSignatureValidator
{
    private const ALLOWED_SIGNATURE_ALGORITHMS = [
        'http://www.w3.org/2000/09/xmldsig#rsa-sha1',
        'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
    ];

    private const ALLOWED_DIGEST_ALGORITHMS = [
        'http://www.w3.org/2000/09/xmldsig#sha1',
        'http://www.w3.org/2001/04/xmlenc#sha256',
    ];

    public function validate(
        string $xmlBytes,
        bool $allowOfficialRedaction = false,
    ): SignatureVerificationResult {
        if (! (bool) config('sefaz.cte.require_signature', true)) {
            return SignatureVerificationResult::Valid;
        }

        if (! $this->usesAllowlistedAlgorithms($xmlBytes)) {
            return SignatureVerificationResult::Invalid;
        }

        try {
            if (! Signer::existsSignature($xmlBytes)) {
                return SignatureVerificationResult::Invalid;
            }

            $tag = str_contains($xmlBytes, '<infEvento') ? 'infEvento' : 'infCte';
            if (Signer::isSigned($xmlBytes, $tag)) {
                return SignatureVerificationResult::Valid;
            }
        } catch (Throwable) {
            // Resultado fechado abaixo; conteúdo remoto nunca entra na exceção.
        }

        if ($allowOfficialRedaction && $this->containsOfficialRedaction($xmlBytes)) {
            return SignatureVerificationResult::NotVerifiableOfficialRedaction;
        }

        return SignatureVerificationResult::Invalid;
    }

    private function usesAllowlistedAlgorithms(string $xmlBytes): bool
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            if (! $dom->loadXML($xmlBytes, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
                return false;
            }

            $xpath = new DOMXPath($dom);
            foreach ($xpath->query('//*[local-name()="SignatureMethod"]/@Algorithm') ?: [] as $attribute) {
                if (! in_array($attribute->nodeValue, self::ALLOWED_SIGNATURE_ALGORITHMS, true)) {
                    return false;
                }
            }
            foreach ($xpath->query('//*[local-name()="DigestMethod"]/@Algorithm') ?: [] as $attribute) {
                if (! in_array($attribute->nodeValue, self::ALLOWED_DIGEST_ALGORITHMS, true)) {
                    return false;
                }
            }

            return true;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function containsOfficialRedaction(string $xmlBytes): bool
    {
        return preg_match('/>9{44}</', $xmlBytes) === 1;
    }
}

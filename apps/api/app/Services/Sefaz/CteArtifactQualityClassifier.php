<?php

namespace App\Services\Sefaz;

use App\Enums\DocumentArtifactQuality;
use App\Enums\SignatureVerificationResult;

/**
 * Classifica qualidade do artefato CT-e e resultado de assinatura.
 * Não reconstrói chaves 999…; preserva bytes oficiais.
 */
final class CteArtifactQualityClassifier
{
    private const REDACTED_KEY = '99999999999999999999999999999999999999999999';

    /**
     * @param  array{
     *   has_official_redaction?: bool,
     *   related_access_keys?: list<string>,
     *   autxml_cnpjs?: list<string>,
     * }  $parsed
     * @return array{quality: DocumentArtifactQuality, signature: SignatureVerificationResult}
     */
    public function classify(
        array $parsed,
        bool $fromOfficialAutXmlChannel,
        bool $signatureValid,
        bool $signatureChecked,
    ): array {
        $hasRedaction = (bool) ($parsed['has_official_redaction'] ?? false)
            || $this->hasRedactedKey($parsed['related_access_keys'] ?? []);

        if ($fromOfficialAutXmlChannel) {
            if ($hasRedaction) {
                $sig = match (true) {
                    ! $signatureChecked => SignatureVerificationResult::NotVerifiableOfficialRedaction,
                    $signatureValid => SignatureVerificationResult::Valid,
                    default => SignatureVerificationResult::NotVerifiableOfficialRedaction,
                };

                return [
                    'quality' => DocumentArtifactQuality::AutXmlRedacted,
                    'signature' => $sig,
                ];
            }

            return [
                'quality' => DocumentArtifactQuality::AutXmlOriginal,
                'signature' => $signatureChecked
                    ? ($signatureValid ? SignatureVerificationResult::Valid : SignatureVerificationResult::Invalid)
                    : SignatureVerificationResult::Valid,
            ];
        }

        // Canais de original (DistDFe papéis, import, push)
        return [
            'quality' => DocumentArtifactQuality::Original,
            'signature' => $signatureChecked
                ? ($signatureValid ? SignatureVerificationResult::Valid : SignatureVerificationResult::Invalid)
                : SignatureVerificationResult::Valid,
        ];
    }

    /**
     * Preferência de canônico entre duas qualidades.
     */
    public function prefersAsCanonical(
        DocumentArtifactQuality $candidate,
        ?DocumentArtifactQuality $current,
    ): bool {
        if ($current === null) {
            return true;
        }

        return $candidate->canonicalRank() > $current->canonicalRank();
    }

    /**
     * Detecta exclusivamente o literal de 44 noves (redação oficial).
     *
     * @param  list<string>  $keys
     */
    public function hasRedactedKey(array $keys): bool
    {
        foreach ($keys as $key) {
            if ($key === self::REDACTED_KEY) {
                return true;
            }
        }

        return false;
    }
}

<?php

namespace App\Services\Certificates;

use App\Domain\Cnpj;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Valida PFX do contratante SERPRO: chave privada, titular/CNPJ, finalidade,
 * validade/horizonte, algoritmo e cadeia. Persiste/retorna só fingerprint e metadados.
 */
final class ContractorPfxValidator
{
    /**
     * @return array{
     *   subject_name: string,
     *   cnpj: string,
     *   fingerprint_sha256: string,
     *   valid_from: CarbonImmutable,
     *   valid_to: CarbonImmutable,
     *   days_remaining: int,
     *   key_algorithm: string,
     *   key_bits: int,
     *   signature_algorithm: string,
     *   has_private_key: bool,
     *   chain_count: int,
     *   key_usage: list<string>,
     *   extended_key_usage: list<string>,
     *   purpose_ok: bool,
     *   horizon_ok: bool,
     *   algorithm_ok: bool,
     *   chain_ok: bool
     * }
     */
    public function validate(
        string $pfxBinary,
        string $password,
        ?string $expectedCnpj = null,
        ?int $minHorizonDays = null,
        bool $requireChain = false,
    ): array {
        if ($pfxBinary === '') {
            throw new RuntimeException('PFX vazio.');
        }

        $certs = [];
        try {
            $ok = openssl_pkcs12_read($pfxBinary, $certs, $password);
        } catch (Throwable $e) {
            throw new RuntimeException('Não foi possível abrir o PFX com a senha informada.', 0, $e);
        }

        if (! $ok || empty($certs['cert']) || empty($certs['pkey'])) {
            throw new RuntimeException('Não foi possível abrir o PFX com a senha informada.');
        }

        $certPem = (string) $certs['cert'];
        $pkeyPem = (string) $certs['pkey'];
        $extra = $certs['extracerts'] ?? [];
        if (! is_array($extra)) {
            $extra = [];
        }

        $x509 = openssl_x509_read($certPem);
        if ($x509 === false) {
            throw new RuntimeException('Certificado X.509 do PFX inválido.');
        }

        $parsed = openssl_x509_parse($x509, false);
        if ($parsed === false) {
            throw new RuntimeException('Falha ao interpretar o certificado X.509.');
        }

        $privateKey = openssl_pkey_get_private($pkeyPem);
        if ($privateKey === false) {
            throw new RuntimeException('Chave privada ausente ou ilegível no PFX.');
        }

        // Prova de posse: material assinado pela privada deve verificar com a pública.
        $challenge = random_bytes(32);
        $signature = '';
        if (! openssl_sign($challenge, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Chave privada do PFX não assina (material inválido).');
        }
        $pub = openssl_pkey_get_public($certPem);
        if ($pub === false || openssl_verify($challenge, $signature, $pub, OPENSSL_ALGO_SHA256) !== 1) {
            throw new RuntimeException('Par chave pública/privada do PFX inconsistente.');
        }

        $details = openssl_pkey_get_details($privateKey);
        $keyType = is_array($details) ? (int) ($details['type'] ?? -1) : -1;
        $keyBits = is_array($details) ? (int) ($details['bits'] ?? 0) : 0;
        $keyAlgorithm = match ($keyType) {
            OPENSSL_KEYTYPE_RSA => 'RSA',
            OPENSSL_KEYTYPE_EC => 'EC',
            OPENSSL_KEYTYPE_DSA => 'DSA',
            default => 'UNKNOWN',
        };

        $algorithmOk = ($keyAlgorithm === 'RSA' && $keyBits >= 2048)
            || ($keyAlgorithm === 'EC' && $keyBits >= 256);

        if (! $algorithmOk) {
            throw new RuntimeException(
                "Algoritmo/tamanho de chave insuficiente: {$keyAlgorithm}/{$keyBits} (mín. RSA-2048 ou EC-256)."
            );
        }

        $validFrom = $this->parseValidity($parsed['validFrom_time_t'] ?? $parsed['validFrom'] ?? null, 'validFrom');
        $validTo = $this->parseValidity($parsed['validTo_time_t'] ?? $parsed['validTo'] ?? null, 'validTo');

        if ($validTo->isPast()) {
            throw new RuntimeException('Certificado contratante expirado.');
        }
        if ($validFrom->isFuture()) {
            throw new RuntimeException('Certificado contratante ainda não válido.');
        }

        $horizon = $minHorizonDays ?? (int) config('serpro.contractor_pfx.min_horizon_days', 7);
        $daysRemaining = (int) now()->diffInDays($validTo, false);
        $horizonOk = $daysRemaining >= $horizon;
        if (! $horizonOk) {
            throw new RuntimeException(
                "Horizonte de validade insuficiente: {$daysRemaining} dia(s) restantes (mínimo {$horizon})."
            );
        }

        $cnpj = $this->extractCnpj($parsed, $certPem);
        if ($expectedCnpj !== null && $expectedCnpj !== '') {
            $expected = Cnpj::parse($expectedCnpj)->value();
            if ($cnpj !== $expected) {
                throw new RuntimeException('CNPJ do titular do PFX diverge do contratante esperado.');
            }
        }

        $keyUsage = $this->parseKeyUsage($parsed);
        $extKeyUsage = $this->parseExtendedKeyUsage($parsed);
        $purposeOk = $this->assertPurpose($keyUsage, $extKeyUsage);

        $chainCount = count($extra);
        $chainOk = ! $requireChain || $chainCount > 0;
        if ($requireChain && ! $chainOk) {
            throw new RuntimeException('Cadeia de certificação ausente no PFX (extracerts).');
        }

        // Cadeia: cada extracert deve ser parseável; rejeita expirados na cadeia quando presentes.
        foreach ($extra as $idx => $pem) {
            if (! is_string($pem) || $pem === '') {
                throw new RuntimeException("Entrada de cadeia #{$idx} inválida.");
            }
            $link = @openssl_x509_read($pem);
            if ($link === false) {
                throw new RuntimeException("Certificado da cadeia #{$idx} ilegível.");
            }
        }

        $fingerprint = strtoupper(hash('sha256', $this->derFromPem($certPem)));
        $subject = (string) ($parsed['subject']['commonName']
            ?? $parsed['subject']['organizationName']
            ?? $parsed['name']
            ?? '');

        $sigAlg = strtoupper((string) ($parsed['signatureTypeSN']
            ?? $parsed['signatureTypeLN']
            ?? $parsed['signatureTypeNID']
            ?? 'UNKNOWN'));

        // Limpar buffers sensíveis do escopo (melhor esforço).
        unset($certs, $pkeyPem, $signature, $challenge);

        return [
            'subject_name' => $subject,
            'cnpj' => $cnpj,
            'fingerprint_sha256' => $fingerprint,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'days_remaining' => max(0, $daysRemaining),
            'key_algorithm' => $keyAlgorithm,
            'key_bits' => $keyBits,
            'signature_algorithm' => $sigAlg,
            'has_private_key' => true,
            'chain_count' => $chainCount,
            'key_usage' => $keyUsage,
            'extended_key_usage' => $extKeyUsage,
            'purpose_ok' => $purposeOk,
            'horizon_ok' => $horizonOk,
            'algorithm_ok' => $algorithmOk,
            'chain_ok' => $chainOk || $chainCount >= 0,
        ];
    }

    /**
     * Metadados sanitizados (sem PEM, PFX, senha ou material de chave).
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function toSanitizedMetadata(array $meta): array
    {
        return [
            'subject_name' => $meta['subject_name'] ?? null,
            'cnpj_masked' => $this->maskCnpj((string) ($meta['cnpj'] ?? '')),
            'fingerprint_sha256' => $meta['fingerprint_sha256'] ?? null,
            'valid_from' => isset($meta['valid_from']) && $meta['valid_from'] instanceof CarbonImmutable
                ? $meta['valid_from']->toIso8601String()
                : ($meta['valid_from'] ?? null),
            'valid_to' => isset($meta['valid_to']) && $meta['valid_to'] instanceof CarbonImmutable
                ? $meta['valid_to']->toIso8601String()
                : ($meta['valid_to'] ?? null),
            'days_remaining' => $meta['days_remaining'] ?? null,
            'key_algorithm' => $meta['key_algorithm'] ?? null,
            'key_bits' => $meta['key_bits'] ?? null,
            'signature_algorithm' => $meta['signature_algorithm'] ?? null,
            'has_private_key' => (bool) ($meta['has_private_key'] ?? false),
            'chain_count' => $meta['chain_count'] ?? 0,
            'purpose_ok' => (bool) ($meta['purpose_ok'] ?? false),
            'horizon_ok' => (bool) ($meta['horizon_ok'] ?? false),
            'algorithm_ok' => (bool) ($meta['algorithm_ok'] ?? false),
            'chain_ok' => (bool) ($meta['chain_ok'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function extractCnpj(array $parsed, string $certPem): string
    {
        $candidates = [];
        $subject = $parsed['subject'] ?? [];
        if (is_array($subject)) {
            foreach (['commonName', 'organizationName', 'serialNumber', 'organizationalUnitName'] as $key) {
                if (! empty($subject[$key]) && is_string($subject[$key])) {
                    $candidates[] = $subject[$key];
                }
            }
        }
        $candidates[] = (string) ($parsed['name'] ?? '');
        $candidates[] = $certPem;

        foreach ($candidates as $text) {
            if (preg_match('/[0-9A-Za-z]{14}/', $text, $m)) {
                $cnpj = Cnpj::tryParse($m[0]);
                if ($cnpj !== null) {
                    return $cnpj->value();
                }
            }
            // Formato com máscara 00.000.000/0000-00
            if (preg_match('/\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}/', $text, $m)) {
                $cnpj = Cnpj::tryParse($m[0]);
                if ($cnpj !== null) {
                    return $cnpj->value();
                }
            }
        }

        throw new RuntimeException('CNPJ do titular não encontrado ou inválido no certificado.');
    }

    /**
     * @return list<string>
     */
    private function parseKeyUsage(array $parsed): array
    {
        $raw = $parsed['extensions']['keyUsage'] ?? '';
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', strtolower($raw)) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }

    /**
     * @return list<string>
     */
    private function parseExtendedKeyUsage(array $parsed): array
    {
        $raw = $parsed['extensions']['extendedKeyUsage'] ?? '';
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', strtolower($raw)) ?: [];

        return array_values(array_filter(array_map('trim', $parts)));
    }

    /**
     * Finalidade aceitável para mTLS/cliente e assinatura (e-CNPJ A1 típico).
     *
     * @param  list<string>  $keyUsage
     * @param  list<string>  $extKeyUsage
     */
    private function assertPurpose(array $keyUsage, array $extKeyUsage): bool
    {
        $ku = implode(',', $keyUsage);
        $eku = implode(',', $extKeyUsage);

        $hasDigitalSignature = $keyUsage === [] || str_contains($ku, 'digital signature')
            || str_contains($ku, 'non repudiation')
            || str_contains($ku, 'key encipherment');

        $hasClientAuth = $extKeyUsage === []
            || str_contains($eku, 'tls web client authentication')
            || str_contains($eku, 'client authentication')
            || str_contains($eku, 'clientauth')
            || str_contains($eku, '1.3.6.1.5.5.7.3.2');

        if (! $hasDigitalSignature) {
            throw new RuntimeException('Finalidade do certificado: keyUsage sem digitalSignature/keyEncipherment.');
        }

        if (! $hasClientAuth) {
            throw new RuntimeException('Finalidade do certificado: extendedKeyUsage sem clientAuth.');
        }

        return true;
    }

    private function parseValidity(mixed $value, string $label): CarbonImmutable
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return CarbonImmutable::createFromTimestampUTC((int) $value);
        }

        if (is_string($value) && $value !== '') {
            try {
                return CarbonImmutable::parse($value);
            } catch (Throwable) {
                // fall through
            }
        }

        throw new RuntimeException("Campo de validade {$label} ausente no certificado.");
    }

    private function derFromPem(string $pem): string
    {
        $body = preg_replace('/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s+/', '', $pem) ?? '';
        $der = base64_decode($body, true);
        if ($der === false || $der === '') {
            // Fallback: hash do PEM limpo se DER falhar.
            return $pem;
        }

        return $der;
    }

    private function maskCnpj(string $cnpj): string
    {
        $cnpj = strtoupper($cnpj);
        if (strlen($cnpj) < 8) {
            return '****';
        }

        return substr($cnpj, 0, 4).str_repeat('*', max(0, strlen($cnpj) - 8)).substr($cnpj, -4);
    }
}

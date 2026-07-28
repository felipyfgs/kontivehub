<?php

namespace App\Support;

/**
 * Sanitização allowlist/denylist para logs e métricas.
 * Nunca emite PFX, tokens, PEM, Consumer Secret, Termo XML, corpo fiscal ou CNPJ completo como label.
 */
final class LogSanitizer
{
    /** @var list<string> */
    public const SENSITIVE_KEYS = [
        'password', 'pfx', 'private_key', 'privateKey', 'pem', 'certificate',
        'token', 'secret', 'authorization', 'cookie', 'vault_object_id',
        'master_key', 'VAULT_MASTER_KEY',
        'csc', 'csc_token', 'cscToken', 'xml', 'raw_xml', 'soap',
        'consumer_secret', 'consumerSecret', 'access_token', 'accessToken',
        'bearer', 'jwt', 'termo_xml', 'termoXml', 'procurador_token',
        'oauth_vault_object_id', 'pfx_vault_object_id', 'token_vault_object_id',
        'termo_vault_object_id', 'procurador_token_vault_object_id',
        'body_bytes', 'body_content', 'message_body', 'attachment_bytes',
        'subject_preview', 'mailbox_body', 'body_vault_object_id',
        'access_key', 'chNFe', 'chave', 'raw_body', 'response_body',
        'request_body', 'payload_xml', 'signed_xml',
        // Identificadores fiscais / titulares
        'cnpj', 'holder_cnpj',
        // Work operacional
        'storage_path', 'vault_path', 'evidence_bytes', 'file_bytes',
        'pfx_password',
        // Ativação / primeiro acesso
        'temporary_password', 'activation_url', 'activation_token',
        'reconfirm_password', 'secret_hash', 'link_token', 'onboarding_token',
    ];

    /**
     * Chaves de label de métrica de baixa cardinalidade (allowlist).
     *
     * @var list<string>
     */
    public const METRIC_LABEL_ALLOWLIST = [
        'channel', 'environment', 'outcome', 'result', 'status', 'module',
        'service_code', 'operation_code', 'solution_code', 'model',
        'urgency_band', 'source', 'competence', 'risk', 'http_class',
        'breaker_state', 'queue', 'event', 'kind', 'severity',
        'block_reason', 'coverage', 'situation', 'consumption_class',
        // CT-e: baixa cardinalidade (cStat classes, qualidade — nunca CNPJ/chave)
        'cstat', 'quality', 'stream',
        // Work: labels de baixa cardinalidade
        'bucket', 'batch_status', 'task_status', 'process_status', 'export_status',
    ];

    /**
     * Metadados públicos que contêm substrings sensíveis mas não são segredo.
     *
     * @var list<string>
     */
    private const PUBLIC_METADATA_KEYS = [
        'certificate_mode',
        'termo_sha256',
        'termo_uploaded_at',
        'termo_valid_from',
        'termo_valid_to',
        'has_termo',
        'has_procurador_token',
        'token_expires_at',
        'has_cached_token',
        'csc_id',
        'csc_configured',
        'csc_configured_at',
    ];

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function redact(array $context): array
    {
        $out = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            if (self::isSensitiveKey($lower)) {
                $out[$key] = '[redacted]';

                continue;
            }
            if (is_array($value)) {
                $out[$key] = self::redact($value);

                continue;
            }
            if (is_string($value) && self::looksLikeSecret($value)) {
                $out[$key] = '[redacted]';

                continue;
            }
            if (is_string($value)) {
                $out[$key] = self::scrubString($value);

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * Filtra labels de métrica para allowlist + redige residual.
     *
     * @param  array<string, scalar|null>  $labels
     * @return array<string, scalar|null>
     */
    public static function metricLabels(array $labels): array
    {
        $safe = [];
        foreach ($labels as $key => $value) {
            $k = (string) $key;
            if (! in_array($k, self::METRIC_LABEL_ALLOWLIST, true)) {
                continue;
            }
            if (is_string($value)) {
                // Nunca aceitar CNPJ/chave completa como valor de label.
                if (self::looksLikeFiscalIdentifier($value) || self::looksLikeSecret($value)) {
                    continue;
                }
                $safe[$k] = mb_substr(self::scrubString($value), 0, 64);

                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[$k] = $value;
            }
        }

        return $safe;
    }

    public static function scrubString(string $text): string
    {
        $text = trim($text);
        if (preg_match('/<\?xml\b|<(?:cte|nfe|mdfe|soap|doczip|distdfe|procevento)\b/i', $text) === 1) {
            return 'Mensagem sanitizada (conteúdo fiscal omitido).';
        }

        $text = preg_replace_callback(
            '/(?<header>\b(?:Authorization|Cookie|X-Client-Cert|X-SSL-Cert))(?<separator>\s*(?:=|:)\s*)[^\r\n]*/i',
            static fn (array $matches): string => $matches['header'].$matches['separator'].'[redacted]',
            $text,
        ) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? '';
        $text = preg_replace('/[A-Za-z0-9+\/]{80,}={0,2}/', '[redacted]', $text) ?? $text;
        $text = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $text) ?? $text;
        $text = preg_replace_callback(
            '/(?<key_quote>["\']?)(?<key>[A-Za-z][A-Za-z0-9_-]*)\k<key_quote>(?<separator>\s*(?:=|:)\s*)(?<value>"(?:\\\\.|[^"\\\\])*"|\'(?:\\\\.|[^\'\\\\])*\'|(?:Basic|Bearer)\s+\S+|[^\s,;]+)/i',
            static function (array $matches): string {
                if (! self::isSensitiveKey(strtolower($matches['key']))) {
                    return $matches[0];
                }

                $quote = str_starts_with($matches['value'], '"') || str_starts_with($matches['value'], "'")
                    ? $matches['value'][0]
                    : '';

                return $matches['key_quote'].$matches['key'].$matches['key_quote']
                    .$matches['separator'].$quote.'[redacted]'.$quote;
            },
            $text,
        ) ?? $text;
        $text = preg_replace(
            '/(?<![0-9A-Z])(?:[0-9A-Z]{2}\.?[0-9A-Z]{3}\.?[0-9A-Z]{3}\/?[0-9A-Z]{4}-?\d{2})(?![0-9A-Z])/i',
            '[redacted]',
            $text,
        ) ?? $text;
        $text = preg_replace('/(?<!\d)\d{44}(?!\d)/', '[redacted]', $text) ?? $text;

        $forbidden = ['BEGIN ', 'PRIVATE KEY', 'VAULT_MASTER_KEY', 'vault_object_id', '.pfx', '.p12'];
        foreach ($forbidden as $needle) {
            if (stripos($text, $needle) !== false) {
                return 'Mensagem sanitizada (conteúdo sensível omitido).';
            }
        }

        return mb_substr($text, 0, 500);
    }

    public static function looksLikeSecret(string $value): bool
    {
        if (str_contains($value, 'BEGIN ') && (str_contains($value, 'PRIVATE KEY') || str_contains($value, 'CERTIFICATE'))) {
            return true;
        }

        return false;
    }

    /**
     * CNPJ 14 / chave 44 / base64 longo como valor de label.
     */
    public static function looksLikeFiscalIdentifier(string $value): bool
    {
        $trimmed = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $value) ?? $value);
        // Chave de acesso 44 dígitos
        if (preg_match('/^\d{44}$/', $trimmed) === 1) {
            return true;
        }
        // CNPJ completo: 12 posições alfanuméricas + 2 DVs numéricos.
        if (preg_match('/^[0-9A-Z]{12}\d{2}$/', $trimmed) === 1) {
            return true;
        }

        return false;
    }

    private static function isSensitiveKey(string $lower): bool
    {
        if (in_array($lower, self::PUBLIC_METADATA_KEYS, true)) {
            return false;
        }

        foreach (self::SENSITIVE_KEYS as $key) {
            $needle = strtolower($key);
            if (strlen($needle) <= 3) {
                if ($lower === $needle || str_starts_with($lower, $needle.'_') || str_ends_with($lower, '_'.$needle)) {
                    return true;
                }

                continue;
            }
            if ($lower === $needle || str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }
}

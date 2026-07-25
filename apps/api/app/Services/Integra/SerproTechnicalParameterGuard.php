<?php

namespace App\Services\Integra;

use RuntimeException;

/**
 * Recusa parâmetros técnicos tenant-facing no gateway (F-3.2).
 * Autor/termo/OAuth/token/ETag/ambiente de contrato vêm só do estado interno.
 */
final class SerproTechnicalParameterGuard
{
    /** @var list<string> */
    public const FORBIDDEN_KEYS = [
        'author_identity',
        'authorIdentity',
        'author_identity_override',
        'authorIdentityOverride',
        'autor_pedido',
        'autorPedidoDados',
        'termo',
        'termo_xml',
        'termoXml',
        'termo_sha256',
        'oauth',
        'access_token',
        'accessToken',
        'jwt_token',
        'jwtToken',
        'consumer_key',
        'consumer_secret',
        'consumerKey',
        'consumerSecret',
        'autenticar_procurador_token',
        'procurador_token',
        'procuradorToken',
        'etag',
        'ETag',
        'if_none_match',
        'If-None-Match',
        'mtls',
        'pfx',
        'pfx_password',
        'certificate_password',
        'contratante',
        'contractor_cnpj',
        'contractorCnpj',
        'serpro_environment_override',
        'contract_id',
        'serpro_contract_id',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function assertClean(array $payload, string $source = 'request'): void
    {
        $hits = $this->findForbidden($payload);
        if ($hits === []) {
            return;
        }

        throw new RuntimeException(
            'Parâmetros técnicos SERPRO recusados em '.$source.': '.implode(', ', $hits),
        );
    }

    /**
     * Remove chaves proibidas (ignore) em vez de falhar — útil para headers extras.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function strip(array $payload): array
    {
        $out = [];
        foreach ($payload as $key => $value) {
            if ($this->isForbiddenKey((string) $key)) {
                continue;
            }
            if (is_array($value)) {
                $out[$key] = $this->strip($value);

                continue;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    public function findForbidden(array $payload, string $prefix = ''): array
    {
        $hits = [];
        foreach ($payload as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if ($this->isForbiddenKey((string) $key)) {
                $hits[] = $path;
            }
            if (is_array($value)) {
                $hits = array_merge($hits, $this->findForbidden($value, $path));
            }
        }

        return array_values(array_unique($hits));
    }

    public function isForbiddenKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));
        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            if (strtolower(str_replace(['-', ' '], '_', $forbidden)) === $normalized) {
                return true;
            }
        }

        return false;
    }
}

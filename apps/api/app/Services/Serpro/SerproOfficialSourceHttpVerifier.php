<?php

namespace App\Services\Serpro;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Compara, sob comando explícito, os documentos oficiais com o snapshot local.
 *
 * Não persiste respostas e nunca alcança gateway, autenticação ou serviço fiscal.
 */
final class SerproOfficialSourceHttpVerifier
{
    private const ALLOWED_HOST = 'apicenter.estaleiro.serpro.gov.br';

    private const ALLOWED_PATH_PREFIX = '/documentacao/api-integra-contador/';

    public function __construct(private readonly SerproDocumentRegistry $registry) {}

    /**
     * @return array{
     *   status: 'PASS'|'REVIEW_REQUIRED',
     *   results: list<array{
     *     source_key: string,
     *     result: string,
     *     http_status: int|null,
     *     hash_result: 'MATCH'|'MISMATCH'|'NOT_COMPUTED',
     *     expected_sha256: string|null,
     *     observed_sha256: string|null
     *   }>
     * }
     */
    public function verify(): array
    {
        try {
            $manifest = $this->registry->loadManifest();
        } catch (Throwable) {
            return $this->reviewRequired('_manifest', 'MANIFEST_INVALID');
        }

        $sources = array_values(array_filter(
            $manifest['sources'],
            static fn (mixed $source): bool => is_array($source)
                && ($source['canonical'] ?? null) === true
                && ($source['verification_kind'] ?? null) === 'HTTP_CONTENT',
        ));

        usort(
            $sources,
            static fn (array $left, array $right): int => strcmp(
                (string) ($left['source_key'] ?? ''),
                (string) ($right['source_key'] ?? ''),
            ),
        );

        $preflight = $this->preflight($sources);
        if ($preflight !== []) {
            return [
                'status' => 'REVIEW_REQUIRED',
                'results' => $preflight,
            ];
        }

        $results = [];
        foreach ($sources as $source) {
            $results[] = $this->verifySource($source);
        }

        return [
            'status' => collect($results)->every(
                static fn (array $result): bool => $result['result'] === 'PASS',
            ) ? 'PASS' : 'REVIEW_REQUIRED',
            'results' => $results,
        ];
    }

    /**
     * Valida todas as coordenadas antes da primeira requisição.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    private function preflight(array $sources): array
    {
        $configuredHosts = array_values(array_map(
            static fn (mixed $host): string => strtolower((string) $host),
            (array) config('serpro.official_source_verification.allowed_hosts', []),
        ));
        $expectedCount = (int) config('serpro.official_source_verification.expected_source_count', 8);
        $configuredPathPrefix = (string) config(
            'serpro.official_source_verification.allowed_path_prefix',
            '',
        );

        if ($configuredHosts !== [self::ALLOWED_HOST]
            || $configuredPathPrefix !== self::ALLOWED_PATH_PREFIX
            || count($sources) !== $expectedCount
        ) {
            return [$this->result('_manifest', 'SOURCE_SET_INVALID')];
        }

        $results = [];
        $seenKeys = [];
        foreach ($sources as $source) {
            $sourceKey = $this->safeSourceKey($source['source_key'] ?? null);
            $url = is_string($source['url'] ?? null) ? $source['url'] : '';
            $expectedHash = is_string($source['content_sha256'] ?? null)
                ? strtolower($source['content_sha256'])
                : null;
            $expectedStatus = $source['expected_http_status'] ?? null;

            $validKey = $sourceKey !== '_invalid_source' && ! isset($seenKeys[$sourceKey]);
            $seenKeys[$sourceKey] = true;
            if (! $validKey
                || ! $this->isAllowedDocumentUrl($url)
                || $expectedStatus !== 200
                || $expectedHash === null
                || preg_match('/\A[a-f0-9]{64}\z/', $expectedHash) !== 1
            ) {
                $results[] = $this->result($sourceKey, 'SOURCE_NOT_ALLOWLISTED', $expectedHash);
            }
        }

        if ($results === []) {
            return [];
        }

        return $results;
    }

    private function isAllowedDocumentUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        return ($parts['scheme'] ?? null) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === self::ALLOWED_HOST
            && ! isset($parts['user'], $parts['pass'], $parts['port'], $parts['query'], $parts['fragment'])
            && str_starts_with((string) ($parts['path'] ?? ''), self::ALLOWED_PATH_PREFIX);
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function verifySource(array $source): array
    {
        $sourceKey = (string) $source['source_key'];
        $expectedHash = strtolower((string) $source['content_sha256']);

        try {
            $response = Http::accept('text/html,application/xhtml+xml')
                ->connectTimeout(max(
                    (int) config('serpro.official_source_verification.connect_timeout_seconds', 5),
                    1,
                ))
                ->timeout(max(
                    (int) config('serpro.official_source_verification.timeout_seconds', 20),
                    1,
                ))
                ->withOptions([
                    'allow_redirects' => false,
                    'stream' => true,
                    'verify' => true,
                ])
                ->get((string) $source['url']);
        } catch (ConnectionException) {
            return $this->result($sourceKey, 'TIMEOUT_OR_CONNECTION_ERROR', $expectedHash);
        } catch (Throwable) {
            return $this->result($sourceKey, 'REQUEST_FAILED', $expectedHash);
        }

        $status = $response->status();
        if ($status >= 300 && $status < 400) {
            $response->close();

            return $this->result($sourceKey, 'REDIRECT_REJECTED', $expectedHash, $status);
        }

        if ($status !== 200) {
            $response->close();

            return $this->result($sourceKey, 'HTTP_STATUS_UNEXPECTED', $expectedHash, $status);
        }

        try {
            $hash = $this->hashWithinLimit($response);
        } catch (Throwable) {
            return $this->result($sourceKey, 'RESPONSE_READ_FAILED', $expectedHash, $status);
        }
        if ($hash === null) {
            return $this->result($sourceKey, 'OVERSIZE', $expectedHash, $status);
        }

        if (! hash_equals($expectedHash, $hash)) {
            return $this->result(
                $sourceKey,
                'HASH_MISMATCH',
                $expectedHash,
                $status,
                'MISMATCH',
                $hash,
            );
        }

        return $this->result($sourceKey, 'PASS', $expectedHash, $status, 'MATCH', $hash);
    }

    private function hashWithinLimit(Response $response): ?string
    {
        $limit = max(
            (int) config('serpro.official_source_verification.max_response_bytes', 5 * 1024 * 1024),
            1,
        );
        $stream = $response->toPsrResponse()->getBody();
        $bytes = 0;
        $context = hash_init('sha256');

        try {
            while (! $stream->eof()) {
                $chunk = $stream->read(min(8192, ($limit - $bytes) + 1));
                if ($chunk === '') {
                    break;
                }

                $bytes += strlen($chunk);
                if ($bytes > $limit) {
                    return null;
                }

                hash_update($context, $chunk);
            }

            return hash_final($context);
        } finally {
            $response->close();
        }
    }

    /**
     * @return array{status: 'REVIEW_REQUIRED', results: list<array<string, mixed>>}
     */
    private function reviewRequired(string $sourceKey, string $result): array
    {
        return [
            'status' => 'REVIEW_REQUIRED',
            'results' => [$this->result($sourceKey, $result)],
        ];
    }

    /**
     * @return array{
     *   source_key: string,
     *   result: string,
     *   http_status: int|null,
     *   hash_result: 'MATCH'|'MISMATCH'|'NOT_COMPUTED',
     *   expected_sha256: string|null,
     *   observed_sha256: string|null
     * }
     */
    private function result(
        string $sourceKey,
        string $result,
        ?string $expectedHash = null,
        ?int $httpStatus = null,
        string $hashResult = 'NOT_COMPUTED',
        ?string $observedHash = null,
    ): array {
        return [
            'source_key' => $sourceKey,
            'result' => $result,
            'http_status' => $httpStatus,
            'hash_result' => $hashResult,
            'expected_sha256' => $expectedHash,
            'observed_sha256' => $observedHash,
        ];
    }

    private function safeSourceKey(mixed $sourceKey): string
    {
        if (! is_string($sourceKey) || preg_match('/\A[a-z0-9_]{1,80}\z/', $sourceKey) !== 1) {
            return '_invalid_source';
        }

        return $sourceKey;
    }
}

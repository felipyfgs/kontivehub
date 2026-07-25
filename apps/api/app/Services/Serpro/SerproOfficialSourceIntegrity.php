<?php

namespace App\Services\Serpro;

use App\Services\Serpro\Catalog\OfficialServiceCatalogManifest;
use DateTimeImmutable;
use RuntimeException;

/**
 * Valida, estritamente offline, a cadeia de proveniência SERPRO.
 */
final class SerproOfficialSourceIntegrity
{
    public const CATALOG_RELATIVE_PATH = 'resources/serpro/official-service-catalog.v2026-07-16.json';

    private const DOCUMENT_HOST = 'apicenter.estaleiro.serpro.gov.br';

    private const SHARED_SOURCE_URLS = [
        'service_catalog' => 'https://apicenter.estaleiro.serpro.gov.br/documentacao/api-integra-contador/pt/catalogo_de_servicos/',
        'services_vs_proxies' => 'https://apicenter.estaleiro.serpro.gov.br/documentacao/api-integra-contador/pt/servicos_vs_procuracoes/',
    ];

    private const EXPECTED_TOTAL = 119;

    private const HTTP_CONTENT = 'HTTP_CONTENT';

    private const NON_CONTENT_KINDS = [
        'DYNAMIC_REFERENCE',
        'TRANSPORT_CHECK',
        'HISTORICAL_REFERENCE',
    ];

    /** Hashes sequenciais publicados no snapshot legado e recusados explicitamente. */
    private const LEGACY_PLACEHOLDER_HASHES = [
        'a1b2c3d4e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff00',
        'b2c3d4e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff0011',
        'c3d4e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff001122',
        'd4e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff00112233',
        'e5f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff0011223344',
        'f60718293a4b5c6d7e8f90112233445566778899aabbccddeeff001122334455',
        '0718293a4b5c6d7e8f90112233445566778899aabbccddeeff00112233445566',
        '18293a4b5c6d7e8f90112233445566778899aabbccddeeff0011223344556677',
        '293a4b5c6d7e8f90112233445566778899aabbccddeeff001122334455667788',
        '3a4b5c6d7e8f90112233445566778899aabbccddeeff00112233445566778899',
    ];

    /**
     * @return array{
     *   manifest: array<string, mixed>,
     *   catalog: array<string, mixed>,
     *   matrix: array<string, mixed>
     * }
     */
    public function loadAndValidate(
        string $manifestPath,
        ?string $catalogPath = null,
        ?string $matrixPath = null,
    ): array {
        $catalogPath ??= (string) config(
            'serpro.official_service_catalog_manifest',
            base_path(self::CATALOG_RELATIVE_PATH),
        );
        $matrixPath ??= (string) config(
            'serpro.power_matrix_manifest',
            resource_path('serpro/power-matrix.v2026-07-18.json'),
        );

        $manifest = $this->readJson($manifestPath, 'manifesto de fontes');
        $this->assertManifest($manifest);

        $catalogReader = new OfficialServiceCatalogManifest;
        $catalog = $catalogReader->load($catalogPath);
        $catalogResult = $catalogReader->validate($catalog, $catalogPath);
        if (! $catalogResult['valid']) {
            throw new RuntimeException('SERPRO_INTEGRITY_CATALOG_INVALID');
        }
        $this->assertOfficialCatalogCounts($catalog);

        $matrix = $this->readJson($matrixPath, 'matriz de poderes');
        $this->assertMatrix($matrix);
        $this->assertCoherence($manifest, $catalog, $matrix, $catalogPath);

        return [
            'manifest' => $manifest,
            'catalog' => $catalog,
            'matrix' => $matrix,
        ];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function assertManifest(array $manifest): void
    {
        foreach (['version', 'retrieved_on', 'sources'] as $field) {
            if (! array_key_exists($field, $manifest)) {
                throw new RuntimeException("SERPRO_INTEGRITY_MANIFEST_FIELD:{$field}");
            }
        }

        $this->assertDate((string) $manifest['version'], 'version');
        $manifestDate = (string) $manifest['retrieved_on'];
        $this->assertDate($manifestDate, 'retrieved_on');

        $sources = $manifest['sources'];
        if (! is_array($sources) || ! array_is_list($sources) || $sources === []) {
            throw new RuntimeException('SERPRO_INTEGRITY_SOURCES_INVALID');
        }

        $keys = [];
        $httpCount = 0;
        foreach ($sources as $index => $source) {
            if (! is_array($source)) {
                throw new RuntimeException("SERPRO_INTEGRITY_SOURCE_INVALID:{$index}");
            }

            $key = trim((string) ($source['source_key'] ?? ''));
            if ($key === '' || preg_match('/^[a-z0-9_]+$/', $key) !== 1) {
                throw new RuntimeException("SERPRO_INTEGRITY_SOURCE_KEY:{$index}");
            }
            if (isset($keys[$key])) {
                throw new RuntimeException("SERPRO_INTEGRITY_SOURCE_DUPLICATE:{$key}");
            }
            $keys[$key] = true;

            foreach (['title', 'document_type', 'verification_kind', 'retrieved_on', 'canonical'] as $field) {
                if (! array_key_exists($field, $source)) {
                    throw new RuntimeException("SERPRO_INTEGRITY_SOURCE_FIELD:{$key}:{$field}");
                }
            }
            if (! is_string($source['title']) || trim($source['title']) === '') {
                throw new RuntimeException("SERPRO_INTEGRITY_SOURCE_TITLE:{$key}");
            }
            if (! is_string($source['document_type']) || trim($source['document_type']) === '') {
                throw new RuntimeException("SERPRO_INTEGRITY_DOCUMENT_TYPE:{$key}");
            }
            if (! is_bool($source['canonical'])) {
                throw new RuntimeException("SERPRO_INTEGRITY_CANONICAL_TYPE:{$key}");
            }

            $sourceDate = (string) $source['retrieved_on'];
            $this->assertDate($sourceDate, "source:{$key}");
            if ($sourceDate > $manifestDate) {
                throw new RuntimeException("SERPRO_INTEGRITY_SOURCE_DATE:{$key}");
            }

            $kind = (string) $source['verification_kind'];
            if ($kind === self::HTTP_CONTENT) {
                $httpCount++;
                $this->assertHttpContentSource($source, $key);

                continue;
            }

            if (! in_array($kind, self::NON_CONTENT_KINDS, true)) {
                throw new RuntimeException("SERPRO_INTEGRITY_KIND:{$key}");
            }
            $this->assertNonContentSource($source, $key, $kind);
        }

        if ($httpCount !== 8) {
            throw new RuntimeException('SERPRO_INTEGRITY_HTTP_SOURCE_COUNT');
        }
        foreach (['service_catalog', 'services_vs_proxies'] as $required) {
            if (! isset($keys[$required])) {
                throw new RuntimeException("SERPRO_INTEGRITY_SOURCE_MISSING:{$required}");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $matrix
     */
    public function assertMatrix(array $matrix): void
    {
        foreach ([
            'matrix_version', 'source_key', 'source_url', 'source_content_sha256',
            'derived_from_catalog', 'catalog_manifest_version', 'matrix_content_sha256',
            'review_status', 'retrieved_on', 'entries',
        ] as $field) {
            if (! array_key_exists($field, $matrix)) {
                throw new RuntimeException("SERPRO_INTEGRITY_MATRIX_FIELD:{$field}");
            }
        }

        if (! is_array($matrix['entries']) || ! array_is_list($matrix['entries'])) {
            throw new RuntimeException('SERPRO_INTEGRITY_MATRIX_ENTRIES');
        }
        if (count($matrix['entries']) !== self::EXPECTED_TOTAL) {
            throw new RuntimeException('SERPRO_INTEGRITY_MATRIX_COUNT');
        }
        $this->assertDate((string) $matrix['retrieved_on'], 'matrix_retrieved_on');
        $this->assertRealHash((string) $matrix['source_content_sha256'], 'matrix_source');
        $this->assertRealHash((string) $matrix['matrix_content_sha256'], 'matrix_content');

        if (! in_array((string) $matrix['review_status'], ['APPROVED', 'REVIEW_REQUIRED'], true)) {
            throw new RuntimeException('SERPRO_INTEGRITY_MATRIX_REVIEW_STATUS');
        }

        $actual = hash('sha256', $this->canonicalJson($matrix['entries']));
        if (! hash_equals((string) $matrix['matrix_content_sha256'], $actual)) {
            throw new RuntimeException('SERPRO_INTEGRITY_MATRIX_CONTENT_HASH');
        }
    }

    public function canonicalJson(mixed $value): string
    {
        $canonical = $this->canonicalize($value);

        return json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function assertHttpContentSource(array $source, string $key): void
    {
        if ($source['canonical'] !== true) {
            throw new RuntimeException("SERPRO_INTEGRITY_HTTP_NOT_CANONICAL:{$key}");
        }
        if (($source['expected_http_status'] ?? null) !== 200) {
            throw new RuntimeException("SERPRO_INTEGRITY_HTTP_STATUS:{$key}");
        }

        $url = $source['url'] ?? null;
        if (! is_string($url) || ! $this->isAllowedDocumentUrl($url)) {
            throw new RuntimeException("SERPRO_INTEGRITY_HTTP_URL:{$key}");
        }

        $this->assertRealHash((string) ($source['content_sha256'] ?? ''), $key);
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function assertNonContentSource(array $source, string $key, string $kind): void
    {
        if ($source['canonical'] !== false) {
            throw new RuntimeException("SERPRO_INTEGRITY_NON_CONTENT_CANONICAL:{$key}");
        }
        if (array_key_exists('content_sha256', $source)) {
            throw new RuntimeException("SERPRO_INTEGRITY_NON_CONTENT_HASH:{$key}");
        }
        if (array_key_exists('expected_http_status', $source)) {
            throw new RuntimeException("SERPRO_INTEGRITY_NON_CONTENT_STATUS:{$key}");
        }

        $url = $source['url'] ?? null;
        if ($kind !== 'HISTORICAL_REFERENCE' && (! is_string($url) || ! $this->isHttpsUrl($url))) {
            throw new RuntimeException("SERPRO_INTEGRITY_NON_CONTENT_URL:{$key}");
        }
        if ($kind === 'HISTORICAL_REFERENCE' && $url !== null && (! is_string($url) || ! $this->isHttpsUrl($url))) {
            throw new RuntimeException("SERPRO_INTEGRITY_HISTORICAL_URL:{$key}");
        }
    }

    private function assertRealHash(string $hash, string $key): void
    {
        if (preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
            throw new RuntimeException("SERPRO_INTEGRITY_HASH_FORMAT:{$key}");
        }
        if (in_array($hash, self::LEGACY_PLACEHOLDER_HASHES, true)) {
            throw new RuntimeException("SERPRO_INTEGRITY_HASH_PLACEHOLDER:{$key}");
        }

        foreach ([1, 2, 4, 8] as $unitLength) {
            $unit = substr($hash, 0, $unitLength);
            if (str_repeat($unit, intdiv(64, $unitLength)) === $hash) {
                throw new RuntimeException("SERPRO_INTEGRITY_HASH_ARTIFICIAL:{$key}");
            }
        }
    }

    private function assertDate(string $value, string $field): void
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value
        ) {
            throw new RuntimeException("SERPRO_INTEGRITY_DATE:{$field}");
        }
    }

    private function isAllowedDocumentUrl(string $url): bool
    {
        if (! $this->isHttpsUrl($url)) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['host'] ?? '')) === self::DOCUMENT_HOST
            && str_starts_with((string) ($parts['path'] ?? ''), '/documentacao/api-integra-contador/')
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && (! isset($parts['port']) || $parts['port'] === 443);
    }

    private function isHttpsUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && isset($parts['host'])
            && ! isset($parts['user'])
            && ! isset($parts['pass']);
    }

    /**
     * @param  array<string, mixed>  $catalog
     */
    private function assertOfficialCatalogCounts(array $catalog): void
    {
        $expected = $catalog['expected_counts'] ?? null;
        if (! is_array($expected)
            || (int) ($expected['total'] ?? 0) !== self::EXPECTED_TOTAL
            || (int) ($expected['PRODUCTION'] ?? 0) !== 98
            || (int) ($expected['PROSPECTION'] ?? 0) !== 19
            || (int) ($expected['UNDER_CONSTRUCTION'] ?? 0) !== 1
            || (int) ($expected['CANCELED'] ?? 0) !== 1
        ) {
            throw new RuntimeException('SERPRO_INTEGRITY_CATALOG_COUNTS');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, mixed>  $catalog
     * @param  array<string, mixed>  $matrix
     */
    private function assertCoherence(array $manifest, array $catalog, array $matrix, string $catalogPath): void
    {
        $sources = [];
        foreach ($manifest['sources'] as $source) {
            if (is_array($source)) {
                $sources[(string) $source['source_key']] = $source;
            }
        }

        $catalogSnapshots = [];
        foreach (($catalog['source_snapshots'] ?? []) as $snapshot) {
            if (is_array($snapshot) && isset($snapshot['url'])) {
                $catalogSnapshots[(string) $snapshot['url']] = (string) ($snapshot['sha256'] ?? '');
            }
        }

        foreach (['service_catalog', 'services_vs_proxies'] as $key) {
            $source = $sources[$key] ?? null;
            if (! is_array($source)) {
                throw new RuntimeException("SERPRO_INTEGRITY_SOURCE_MISSING:{$key}");
            }
            $url = (string) ($source['url'] ?? '');
            $hash = (string) ($source['content_sha256'] ?? '');
            if ($url !== self::SHARED_SOURCE_URLS[$key]
                || ! isset($catalogSnapshots[$url])
                || ! hash_equals($hash, $catalogSnapshots[$url])
            ) {
                throw new RuntimeException("SERPRO_INTEGRITY_CATALOG_SOURCE_MISMATCH:{$key}");
            }
        }

        $proxySource = $sources['services_vs_proxies'];
        if ((string) $matrix['source_key'] !== 'services_vs_proxies'
            || ! hash_equals((string) $proxySource['url'], (string) $matrix['source_url'])
            || ! hash_equals((string) $proxySource['content_sha256'], (string) $matrix['source_content_sha256'])
        ) {
            throw new RuntimeException('SERPRO_INTEGRITY_MATRIX_SOURCE_MISMATCH');
        }
        if ((string) $matrix['derived_from_catalog'] !== basename($catalogPath)
            || (string) $matrix['catalog_manifest_version'] !== (string) ($catalog['manifest_version'] ?? '')
        ) {
            throw new RuntimeException('SERPRO_INTEGRITY_MATRIX_ORIGIN');
        }

        $catalogEntries = $catalog['entries'];
        $matrixEntries = $matrix['entries'];
        if (count($catalogEntries) !== self::EXPECTED_TOTAL || count($matrixEntries) !== self::EXPECTED_TOTAL) {
            throw new RuntimeException('SERPRO_INTEGRITY_RESOURCE_COUNT');
        }

        $catalogByKey = [];
        foreach ($catalogEntries as $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException('SERPRO_INTEGRITY_CATALOG_ENTRY');
            }
            $key = (string) ($entry['operation_key'] ?? '');
            $catalogByKey[$key] = $entry;
        }

        $seen = [];
        foreach ($matrixEntries as $entry) {
            if (! is_array($entry)) {
                throw new RuntimeException('SERPRO_INTEGRITY_MATRIX_ENTRY');
            }
            $key = (string) ($entry['operation_key'] ?? '');
            if ($key === '' || isset($seen[$key]) || ! isset($catalogByKey[$key])) {
                throw new RuntimeException('SERPRO_INTEGRITY_MATRIX_OWNERSHIP');
            }
            $seen[$key] = true;
            $catalogEntry = $catalogByKey[$key];

            foreach (['id_sistema', 'id_servico', 'route', 'official_state', 'proxy_rule'] as $field) {
                if ((string) ($entry[$field] ?? '') !== (string) ($catalogEntry[$field] ?? '')) {
                    throw new RuntimeException("SERPRO_INTEGRITY_MATRIX_ENTRY_MISMATCH:{$key}:{$field}");
                }
            }

            $matrixPowers = $this->normalizedPowers($entry['required_proxy_powers'] ?? []);
            $catalogPowers = $this->normalizedPowers($catalogEntry['required_proxy_powers'] ?? []);
            if ($matrixPowers !== $catalogPowers) {
                throw new RuntimeException("SERPRO_INTEGRITY_MATRIX_ENTRY_MISMATCH:{$key}:powers");
            }
        }
        if (count($seen) !== count($catalogByKey)) {
            throw new RuntimeException('SERPRO_INTEGRITY_MATRIX_NOT_ONE_TO_ONE');
        }
    }

    /**
     * @return list<string>
     */
    private function normalizedPowers(mixed $value): array
    {
        if (! is_array($value)) {
            throw new RuntimeException('SERPRO_INTEGRITY_POWERS_INVALID');
        }
        $powers = array_values(array_unique(array_map(
            static fn (mixed $power): string => strtoupper(trim((string) $power)),
            $value,
        )));
        sort($powers, SORT_STRING);

        return $powers;
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path, string $label): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("SERPRO_INTEGRITY_FILE_MISSING:{$label}");
        }
        $raw = file_get_contents($path);
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException("SERPRO_INTEGRITY_FILE_UNREADABLE:{$label}");
        }

        /** @var mixed $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException("SERPRO_INTEGRITY_JSON_OBJECT:{$label}");
        }

        return $decoded;
    }
}

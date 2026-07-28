<?php

namespace Tests\Unit\CodeQuality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Gates de arquitetura para lotes 8–11 da change canonicalize-laravel-api.
 */
final class RuntimeCanonicalizationArchitectureTest extends TestCase
{
    #[Test]
    public function strict_lazy_loading_is_enabled_outside_production(): void
    {
        $provider = file_get_contents(dirname(__DIR__, 3).'/app/Providers/AppServiceProvider.php');
        self::assertNotFalse($provider);
        self::assertMatchesRegularExpression(
            '/Model::preventLazyLoading\s*\(/',
            $provider,
            'AppServiceProvider deve habilitar preventLazyLoading em local/testing.',
        );
    }

    #[Test]
    public function scheduled_commands_use_overlap_and_singleton_locks(): void
    {
        $console = file_get_contents(dirname(__DIR__, 3).'/routes/console.php');
        self::assertNotFalse($console);
        self::assertStringContainsString('withoutOverlapping', $console);
        self::assertStringContainsString('releaseOnTerminationSignals: true', $console);
        self::assertStringContainsString('onOneServer', $console);

        // Todos os Schedule::command com frequência, exceto heartbeat, devem passar pelo helper de lock.
        preg_match_all('/Schedule::command\(([^)]+)\)/', $console, $matches);
        $commands = $matches[0] ?? [];
        self::assertGreaterThanOrEqual(20, count($commands));

        $locked = substr_count($console, '$lock(Schedule::command');
        // heartbeat fica de fora do lock agressivo
        self::assertGreaterThanOrEqual(count($commands) - 2, $locked);
    }

    #[Test]
    public function queued_jobs_declare_tries_and_timeout(): void
    {
        $root = dirname(__DIR__, 3).'/app/Jobs';
        $missing = [];

        foreach ($this->phpFiles($root) as $file) {
            $contents = file_get_contents($file);
            if ($contents === false || ! str_contains($contents, 'ShouldQueue')) {
                continue;
            }

            $hasTries = (bool) preg_match('/\$tries\b/', $contents);
            $hasTimeout = (bool) preg_match('/\$timeout\b/', $contents);
            if (! $hasTries || ! $hasTimeout) {
                $missing[] = $this->relative($file).' tries='.(int) $hasTries.' timeout='.(int) $hasTimeout;
            }
        }

        self::assertSame([], $missing, "Jobs sem tries/timeout:\n".implode("\n", $missing));
    }

    #[Test]
    public function queue_retry_windows_exceed_the_longest_job_timeout(): void
    {
        $apiRoot = dirname(__DIR__, 3);
        $job = (string) file_get_contents($apiRoot.'/app/Jobs/ProcessDocumentImportBatchJob.php');
        $queue = (string) file_get_contents($apiRoot.'/config/queue.php');

        self::assertMatchesRegularExpression('/implements ShouldBeUnique, ShouldQueue/', $job);
        self::assertMatchesRegularExpression('/->lazyById\s*\(/', $job);
        self::assertMatchesRegularExpression('/public int \$timeout = 900;/', $job);
        foreach (['DB_QUEUE_RETRY_AFTER', 'BEANSTALKD_QUEUE_RETRY_AFTER'] as $environmentKey) {
            self::assertMatchesRegularExpression(
                "/env\\('{$environmentKey}', 960\\)/",
                $queue,
                "{$environmentKey} deve permanecer acima do timeout máximo de 900 segundos.",
            );
        }
        self::assertMatchesRegularExpression(
            "/REDIS_QUEUE_RETRY_AFTER', env\\('ADN_LOCK_TTL_SECONDS', 960\\)/",
            $queue,
            'REDIS_QUEUE_RETRY_AFTER deve permanecer acima do timeout máximo de 900 segundos.',
        );
    }

    #[Test]
    public function console_commands_avoid_unbounded_get_all_materialization(): void
    {
        $root = dirname(__DIR__, 3).'/app/Console/Commands';
        if (! is_dir($root)) {
            self::markTestSkipped('Sem Commands.');
        }

        $violations = [];
        foreach ($this->phpFiles($root) as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            // Materialização perigosa: ->get() / ::all() sem chunk/lazy/cursor no mesmo arquivo.
            $hasAll = (bool) preg_match('/::all\s*\(\s*\)/', $contents);
            $hasUnboundedGet = (bool) preg_match('/->get\s*\(\s*\)/', $contents);
            $hasBounded = (bool) preg_match('/chunkById|lazyById|->lazy\s*\(|->cursor\s*\(|->limit\s*\(/', $contents);

            if (($hasAll || $hasUnboundedGet) && ! $hasBounded) {
                // Permitir arquivos apenas com get() em queries claramente limitadas por first/find.
                if (preg_match_all('/->get\s*\(\s*\)/', $contents) > 0 && ! $hasAll) {
                    // heurística: se há ->limit( antes no arquivo, ok
                    if (preg_match('/->limit\s*\(/', $contents)) {
                        continue;
                    }
                }
                $violations[] = $this->relative($file);
            }
        }

        // Baseline controlada: não aumentar comandos sem estratégia de volume.
        self::assertLessThanOrEqual(
            15,
            count($violations),
            'Comandos com possível materialização unbounded: '.implode(', ', $violations),
        );
    }

    #[Test]
    public function without_global_scope_bypasses_are_tenant_or_key_constrained(): void
    {
        $root = dirname(__DIR__, 3).'/app';
        $risky = [];

        foreach ($this->phpFiles($root) as $file) {
            if (str_contains($file, '/tests/') || str_contains($file, 'vendor')) {
                continue;
            }
            $contents = file_get_contents($file);
            if ($contents === false || ! str_contains($contents, 'withoutGlobalScope')) {
                continue;
            }

            // Restrição: tenant_id, PK, claim limitado por id, ou client_id confiado.
            $constrained = (bool) preg_match(
                '/tenant_id|client_id|whereKey\s*\(|->find(?:OrFail)?\s*\(|whereIn\s*\(\s*[\'"]id[\'"]|PrivilegedTenant|withoutTenantScope|where\s*\(\s*[\'"]id[\'"]|->limit\s*\(|chunkById\s*\(|lazyById\s*\(/',
                $contents,
            );
            if (! $constrained) {
                $risky[] = $this->relative($file);
            }
        }

        self::assertSame([], $risky, 'Bypasses sem restrição aparente: '.implode(', ', $risky));
    }

    #[Test]
    public function concrete_models_classify_factory_or_readonly(): void
    {
        $modelsRoot = dirname(__DIR__, 3).'/app/Models';
        $factoriesRoot = dirname(__DIR__, 3).'/database/factories';
        $classification = dirname(__DIR__, 3).'/tests/Fixtures/model-classification.json';

        self::assertFileExists($classification, 'Classificação canônica de models ausente.');
        $map = json_decode((string) file_get_contents($classification), true);
        self::assertIsArray($map);
        self::assertArrayHasKey('read_only_or_pivot', $map);
        self::assertArrayHasKey('requires_factory', $map);

        $factoryBasenames = [];
        foreach ($this->phpFiles($factoriesRoot) as $file) {
            $base = basename($file, 'Factory.php');
            if ($base !== basename($file)) {
                $factoryBasenames[$base] = true;
            }
        }

        $required = $map['requires_factory'] ?? [];
        $missing = [];
        foreach ($required as $model) {
            if (! isset($factoryBasenames[$model])) {
                $missing[] = $model;
            }
        }

        self::assertSame([], $missing, 'Models concretos sem factory: '.implode(', ', $missing));
    }

    /** @return list<string> */
    private function phpFiles(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    private function relative(string $absolute): string
    {
        $apiRoot = dirname(__DIR__, 3);

        return 'apps/api/'.ltrim(substr($absolute, strlen($apiRoot)), '/');
    }
}

<?php

namespace Tools\CodeQuality;

use InvalidArgumentException;
use RuntimeException;

class BackendInventoryBuilder
{
    public const SCOPE_COMMAND = 'git ls-files --cached --others --exclude-standard apps/api apps/web';

    public function __construct(private readonly PhpSymbolCollector $phpCollector = new PhpSymbolCollector) {}

    /**
     * @param  list<string>  $repoPaths
     * @param  array<string, array{symbols: list<array<string, mixed>>, parseErrors: list<array<string, mixed>>}>  $pythonResults
     * @return array<string, mixed>
     */
    public function build(string $apiRoot, array $repoPaths, array $pythonResults = []): array
    {
        $apiRoot = rtrim($apiRoot, '/');
        $rootRealPath = realpath($apiRoot);
        if ($rootRealPath === false || ! is_dir($rootRealPath)) {
            throw new InvalidArgumentException("Raiz API inexistente: {$apiRoot}");
        }

        $paths = array_values(array_unique(array_filter(array_map('trim', $repoPaths))));
        sort($paths, SORT_STRING);

        $files = [];
        $symbols = [];
        foreach ($paths as $repoPath) {
            if (! str_starts_with($repoPath, 'apps/api/')) {
                throw new InvalidArgumentException("Path fora de apps/api: {$repoPath}");
            }

            $relative = substr($repoPath, strlen('apps/api/'));
            $absolute = $rootRealPath.'/'.$relative;
            $resolved = realpath($absolute);
            if ($resolved === false || ! is_file($resolved) || ! str_starts_with($resolved, $rootRealPath.'/')) {
                throw new RuntimeException("Arquivo do inventário não encontrado ou fora da raiz: {$repoPath}");
            }

            $contents = file_get_contents($resolved);
            if ($contents === false) {
                throw new RuntimeException("Não foi possível ler {$repoPath}");
            }

            $language = $this->language($repoPath);
            $parse = ['symbols' => [], 'parseErrors' => []];
            if ($language === 'php') {
                $parse = $this->phpCollector->collect($contents, $repoPath);
            } elseif ($language === 'python') {
                $parse = $pythonResults[$repoPath] ?? [
                    'symbols' => [],
                    'parseErrors' => [[
                        'language' => 'python',
                        'line' => null,
                        'message' => 'Coletor Python não forneceu resultado para o arquivo.',
                    ]],
                ];
            }

            foreach ($parse['symbols'] as $symbol) {
                $symbols[] = $symbol;
            }

            $files[] = [
                'path' => $repoPath,
                'app' => 'api',
                'category' => $this->category($repoPath),
                'language' => $language,
                'bytes' => strlen($contents),
                'lines' => $language === 'image' ? 0 : $this->lineCount($contents),
                'sha256' => hash('sha256', $contents),
                'executable' => in_array($language, ['php', 'python'], true),
                'symbolCount' => count($parse['symbols']),
                'parseErrors' => $parse['parseErrors'],
            ];
        }

        usort($symbols, fn (array $left, array $right): int => $left['id'] <=> $right['id']);
        $summary = $this->summary($files, $symbols);
        $core = [
            'schemaVersion' => 1,
            'scope' => [
                'command' => self::SCOPE_COMMAND,
                'roots' => ['apps/api', 'apps/web'],
                'excludedByGitIgnore' => true,
            ],
            'summary' => $summary,
            'files' => $files,
            'symbols' => $symbols,
        ];

        return [
            'schemaVersion' => 1,
            'scope' => $core['scope'],
            'digest' => hash('sha256', $this->canonicalJson($core)),
            'summary' => $summary,
            'files' => $files,
            'symbols' => $symbols,
        ];
    }

    private function language(string $path): string
    {
        $basename = basename($path);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($basename === 'composer.lock') {
            return 'json';
        }

        return match ($extension) {
            'php' => 'php',
            'ts', 'tsx' => 'typescript',
            'js', 'mjs', 'cjs' => 'javascript',
            'vue' => 'vue',
            'py' => 'python',
            'json' => 'json',
            'yaml', 'yml' => 'yaml',
            'xml' => 'xml',
            'xsd' => 'xsd',
            'css' => 'css',
            'md' => 'markdown',
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico' => 'image',
            'sh', 'bash' => 'shell',
            'txt', 'example', 'lock' => 'text',
            default => 'other',
        };
    }

    private function category(string $path): string
    {
        $lower = strtolower($path);
        $basename = basename($lower);

        return match (true) {
            str_starts_with($path, 'apps/api/app/') => 'application',
            str_starts_with($path, 'apps/api/bootstrap/') => 'bootstrap',
            str_starts_with($path, 'apps/api/config/') => 'configuration',
            str_starts_with($path, 'apps/api/routes/') => 'route',
            str_starts_with($path, 'apps/api/database/migrations/') => 'migration',
            str_starts_with($path, 'apps/api/database/factories/') => 'factory',
            str_starts_with($path, 'apps/api/database/seeders/') => 'seeder',
            str_contains($lower, '/fixtures/') => 'fixture',
            str_starts_with($path, 'apps/api/tests/') => 'test',
            str_starts_with($path, 'apps/api/resources/') => 'resource',
            str_starts_with($path, 'apps/api/rpa/') => str_starts_with($basename, 'test_') ? 'test' : 'rpa',
            str_starts_with($path, 'apps/api/public/') => 'public-asset',
            str_starts_with($path, 'apps/api/storage/') => 'storage-placeholder',
            in_array($basename, ['composer.json', 'composer.lock'], true) => 'dependency-manifest',
            in_array($basename, ['artisan', 'phpunit.xml', '.gitignore', '.env.example'], true) => 'control',
            str_ends_with($lower, '.md') => 'documentation',
            default => 'other',
        };
    }

    private function lineCount(string $contents): int
    {
        if ($contents === '') {
            return 0;
        }

        return substr_count($contents, "\n") + (str_ends_with($contents, "\n") ? 0 : 1);
    }

    /**
     * @param  list<array<string, mixed>>  $files
     * @param  list<array<string, mixed>>  $symbols
     * @return array<string, mixed>
     */
    private function summary(array $files, array $symbols): array
    {
        $parseErrors = array_sum(array_map(fn (array $file): int => count($file['parseErrors']), $files));

        return [
            'files' => count($files),
            'symbols' => count($symbols),
            'executableFiles' => count(array_filter($files, fn (array $file): bool => $file['executable'])),
            'parseErrors' => $parseErrors,
            'byApp' => ['api' => count($files)],
            'byCategory' => $this->countsBy($files, 'category'),
            'byLanguage' => $this->countsBy($files, 'language'),
            'bySymbolKind' => $this->countsBy($symbols, 'kind'),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function countsBy(array $rows, string $key): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $value = (string) $row[$key];
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

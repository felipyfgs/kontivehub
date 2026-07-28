<?php

namespace Tests\Unit\CodeQuality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ControllerBoundaryArchitectureTest extends TestCase
{
    private const INLINE_VALIDATION_BASELINE = 88;

    private const DIRECT_SERIALIZATION_BASELINE = 99;

    private const EXCEPTION_MESSAGE_USAGE_BASELINE = 74;

    #[Test]
    public function controller_boundary_debt_does_not_increase(): void
    {
        $files = $this->controllerFiles();
        $inlineValidation = $this->matchingOccurrences(
            $files,
            '/(?:\$request->validate\s*\(|Validator::make\s*\(|->validateWithBag\s*\()/',
        );
        $directSerialization = $this->matchingOccurrences(
            $files,
            '/->to(?:Public|Sanitized|GlobalSanitized|SanitizedAdmin|Detail|List|Platform|Tenant)Array\s*\(/',
        );
        $exceptionMessageUsages = $this->matchingOccurrences(
            $files,
            '/[\'"]message[\'"]\s*=>\s*\$[A-Za-z_][A-Za-z0-9_]*->getMessage\s*\(\)/',
        );

        self::assertLessThanOrEqual(
            self::INLINE_VALIDATION_BASELINE,
            count($inlineValidation),
            'Novos controllers com validação inline: '.implode(', ', $inlineValidation),
        );
        self::assertLessThanOrEqual(
            self::DIRECT_SERIALIZATION_BASELINE,
            count($directSerialization),
            'Novos controllers serializando Models/DTOs diretamente: '.implode(', ', $directSerialization),
        );
        self::assertLessThanOrEqual(
            self::EXCEPTION_MESSAGE_USAGE_BASELINE,
            count($exceptionMessageUsages),
            'Novos usos diretos de mensagens de exceptions em controllers: '.implode(', ', $exceptionMessageUsages),
        );
    }

    #[Test]
    public function controllers_do_not_depend_on_http_provider_implementations(): void
    {
        $directIntegrations = $this->matchingFiles(
            $this->controllerFiles(),
            '/(?:use\s+Illuminate\\\\Support\\\\Facades\\\\Http(?:\s+as\s+\w+)?\s*;|\\\\Illuminate\\\\Support\\\\Facades\\\\Http::|use\s+GuzzleHttp\\\\|new\s+\\\\?GuzzleHttp\\\\|\\\\?curl_(?:init|exec)\s*\()/',
        );

        self::assertSame(
            [],
            $directIntegrations,
            'Controllers devem depender de Actions/Services e ports: '.implode(', ', $directIntegrations),
        );
    }

    /** @return list<string> */
    private function controllerFiles(): array
    {
        $apiRoot = dirname(__DIR__, 3);
        $controllerRoot = $apiRoot.'/app/Http/Controllers';
        $files = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($controllerRoot)) as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function matchingFiles(array $files, string $pattern): array
    {
        $apiRoot = dirname(__DIR__, 3);
        $matches = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if ($contents === false || preg_match($pattern, $contents) !== 1) {
                continue;
            }

            $matches[] = 'apps/api/'.ltrim(substr($file, strlen($apiRoot)), '/');
        }

        return $matches;
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function matchingOccurrences(array $files, string $pattern): array
    {
        $apiRoot = dirname(__DIR__, 3);
        $matches = [];

        foreach ($files as $file) {
            $contents = file_get_contents($file);
            if ($contents === false) {
                continue;
            }

            $count = preg_match_all($pattern, $contents);
            if ($count === false || $count === 0) {
                continue;
            }

            $relativePath = 'apps/api/'.ltrim(substr($file, strlen($apiRoot)), '/');
            array_push($matches, ...array_fill(0, $count, $relativePath));
        }

        return $matches;
    }
}

<?php

namespace Tests\Unit\CodeQuality;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class ApiExceptionArchitectureTest extends TestCase
{
    public function test_central_api_renderer_does_not_route_failures_by_exception_message(): void
    {
        $apiRoot = dirname(__DIR__, 3);
        $bootstrap = (string) file_get_contents($apiRoot.'/bootstrap/app.php');

        $this->assertStringContainsString('ApiDomainException', $bootstrap);
        $this->assertStringNotContainsString('getMessage()', $bootstrap);
        $this->assertStringNotContainsString("'ASSISTANT_DISABLED'", $bootstrap);
        $this->assertStringNotContainsString("'COMMUNICATION_DISABLED'", $bootstrap);

        $baseException = (string) file_get_contents($apiRoot.'/app/Exceptions/ApiDomainException.php');
        $this->assertStringNotContainsString('ShouldntReport', $baseException);
    }

    public function test_application_does_not_use_builtin_domain_exception_as_a_string_code(): void
    {
        $apiRoot = dirname(__DIR__, 3);
        $paths = [$apiRoot.'/app'];
        $violations = [];
        $pattern = <<<'REGEX'
            ~(?:
                use\s+\\?DomainException(?:\s+as\s+\w+)?\s*;
                |new\s+\\?DomainException\b
                |catch\s*\([^)]*(?<![A-Za-z0-9_])\\?DomainException\b
            )~x
            REGEX;

        foreach ($this->phpFiles($paths) as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match($pattern, $contents) === 1) {
                $violations[] = 'apps/api/'.ltrim(substr($file, strlen($apiRoot)), '/');
            }
        }

        $this->assertSame([], $violations, 'DomainException genérica no código de aplicação: '.implode(', ', $violations));
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function phpFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = $path;

                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path));
            foreach ($iterator as $file) {
                if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}

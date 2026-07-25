<?php

namespace Tests\Unit\CodeQuality;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tools\CodeQuality\BackendInventoryBuilder;

class BackendInventoryBuilderTest extends TestCase
{
    public function test_builds_deterministic_file_and_symbol_inventory(): void
    {
        $root = dirname(__DIR__, 3);
        $paths = [
            'apps/api/composer.json',
            'apps/api/tools/code-quality/src/PhpSymbolCollector.php',
        ];
        $builder = new BackendInventoryBuilder;

        $first = $builder->build($root, array_reverse($paths));
        $second = $builder->build($root, $paths);

        $this->assertSame($first, $second);
        $this->assertSame(2, $first['summary']['files']);
        $this->assertSame(1, $first['summary']['executableFiles']);
        $this->assertSame(0, $first['summary']['parseErrors']);
        $this->assertGreaterThan(10, $first['summary']['symbols']);
        $this->assertSame('dependency-manifest', $first['files'][0]['category']);
        $this->assertSame('json', $first['files'][0]['language']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['digest']);
        $this->assertSame(
            BackendInventoryBuilder::SCOPE_COMMAND,
            $first['scope']['command'],
        );
    }

    public function test_missing_python_result_is_an_explicit_parse_error(): void
    {
        $inventory = (new BackendInventoryBuilder)->build(dirname(__DIR__, 3), [
            'apps/api/rpa/fgts_digital/worker.py',
        ]);

        $this->assertSame(1, $inventory['summary']['parseErrors']);
        $this->assertSame('python', $inventory['files'][0]['parseErrors'][0]['language']);
        $this->assertStringContainsString('não forneceu resultado', $inventory['files'][0]['parseErrors'][0]['message']);
    }

    public function test_rejects_paths_outside_api_scope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new BackendInventoryBuilder)->build(dirname(__DIR__, 3), ['apps/web/package.json']);
    }
}

<?php

namespace Tests\Unit\CodeQuality;

use PHPUnit\Framework\TestCase;
use Tools\CodeQuality\PhpSymbolCollector;

class PhpSymbolCollectorTest extends TestCase
{
    public function test_collects_named_and_anonymous_declarations_with_metrics(): void
    {
        $result = (new PhpSymbolCollector)->collect(
            $this->fixture('php-valid.php.fixture'),
            'apps/api/app/Example.php',
        );

        $this->assertSame([], $result['parseErrors']);
        $this->assertSame(
            ['class', 'function', 'arrow-function', 'class', 'method', 'closure'],
            array_column($result['symbols'], 'kind'),
        );
        $this->assertContains('Fixtures\CodeQuality\Example::execute', array_column($result['symbols'], 'qualifiedName'));

        $method = collect($result['symbols'])->firstWhere('qualifiedName', 'Fixtures\CodeQuality\Example::execute');
        $this->assertIsArray($method);
        $this->assertSame('items', $method['parameters'][0]['name']);
        $this->assertSame(1, $method['metrics']['parameterCount']);
        $this->assertGreaterThan(0, $method['metrics']['tokenCount']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $method['fingerprint']);
    }

    public function test_invalid_syntax_is_reported_without_partial_symbols(): void
    {
        $result = (new PhpSymbolCollector)->collect(
            $this->fixture('php-invalid.php.fixture'),
            'apps/api/app/Invalid.php',
        );

        $this->assertSame([], $result['symbols']);
        $this->assertSame('php', $result['parseErrors'][0]['language']);
        $this->assertSame(3, $result['parseErrors'][0]['line']);
        $this->assertStringNotContainsString("\n", $result['parseErrors'][0]['message']);
    }

    private function fixture(string $name): string
    {
        $path = dirname(__DIR__, 2).'/Fixtures/CodeQuality/'.$name;
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        return $contents;
    }
}

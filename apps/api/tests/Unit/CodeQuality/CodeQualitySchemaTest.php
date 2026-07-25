<?php

namespace Tests\Unit\CodeQuality;

use PHPUnit\Framework\TestCase;

class CodeQualitySchemaTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $schema;

    protected function setUp(): void
    {
        parent::setUp();

        $path = dirname(__DIR__, 3).'/resources/code-quality/schema.json';
        $this->assertFileExists($path);

        $schema = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($schema);
        $this->schema = $schema;
    }

    public function test_schema_declares_all_versioned_artifacts(): void
    {
        $definitions = $this->schema['$defs'] ?? [];

        $this->assertIsArray($definitions);
        foreach (['inventory', 'ledger', 'findings', 'summaryMirror'] as $artifact) {
            $this->assertArrayHasKey($artifact, $definitions);
            $this->assertSame(1, $definitions[$artifact]['properties']['schemaVersion']['const'] ?? null);
        }
    }

    public function test_review_states_and_severities_are_closed(): void
    {
        $this->assertSame(
            ['pending', 'reviewed-no-finding', 'reviewed-with-findings', 'excluded-with-reason'],
            $this->schema['$defs']['reviewStatus']['enum'] ?? null,
        );
        $this->assertSame(
            ['P0', 'P1', 'P2', 'P3'],
            $this->schema['$defs']['finding']['properties']['severity']['enum'] ?? null,
        );
    }

    public function test_scope_excludes_generated_and_dependency_trees(): void
    {
        $pathRule = $this->schema['$defs']['relativePath'] ?? [];

        $this->assertSame('^(apps/(api|web)|docs)/', $pathRule['pattern'] ?? null);
        $this->assertStringContainsString('vendor', (string) ($pathRule['not']['pattern'] ?? ''));
        $this->assertStringContainsString('node_modules', (string) ($pathRule['not']['pattern'] ?? ''));
        $this->assertSame(
            'git ls-files --cached --others --exclude-standard apps/api apps/web',
            $this->schema['$defs']['scope']['properties']['command']['const'] ?? null,
        );
    }

    public function test_summary_mirror_binds_both_app_digests(): void
    {
        $summary = $this->schema['$defs']['summaryMirror'];

        $this->assertContains('inventoryDigests', $summary['required']);
        $this->assertSame(['api', 'web'], $summary['properties']['inventoryDigests']['required']);
        $this->assertFalse($summary['properties']['inventoryDigests']['additionalProperties']);
    }
}

<?php

namespace Tests\Unit\CodeQuality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tools\CodeQuality\ArtifactSetManager;

class CanonicalCodeQualityArtifactsTest extends TestCase
{
    #[Test]
    public function canonical_artifacts_are_bijective_and_have_identical_summaries(): void
    {
        $root = getenv('CODE_QUALITY_ARTIFACT_ROOT') ?: dirname(__DIR__, 3).'/resources/code-quality/artifacts';

        $result = (new ArtifactSetManager)->loadAndValidate($root);

        $this->assertGreaterThan(0, $result['summary']['api']['files']);
        $this->assertGreaterThan(0, $result['summary']['api']['symbols']);
        $this->assertGreaterThan(0, $result['summary']['web']['files']);
        $this->assertGreaterThan(0, $result['summary']['web']['symbols']);
        $this->assertSame(
            file_get_contents($root.'/api/summary.json'),
            file_get_contents($root.'/web/summary.json'),
        );
    }
}

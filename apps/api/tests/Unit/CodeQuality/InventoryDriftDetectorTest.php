<?php

namespace Tests\Unit\CodeQuality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tools\CodeQuality\InventoryDriftDetector;

class InventoryDriftDetectorTest extends TestCase
{
    #[Test]
    public function replacement_with_the_same_counts_is_reported_by_identity(): void
    {
        $expected = $this->inventory('apps/api/app/Old.php', 'old@1', 'a');
        $current = $this->inventory('apps/api/app/New.php', 'new@1', 'b');

        $drift = (new InventoryDriftDetector)->compare($expected, $current);

        $this->assertSame(['apps/api/app/Old.php'], $drift['missingFiles']);
        $this->assertSame(['apps/api/app/New.php'], $drift['unexpectedFiles']);
        $this->assertSame(['old@1'], $drift['missingSymbols']);
        $this->assertSame(['new@1'], $drift['unexpectedSymbols']);
    }

    #[Test]
    public function content_change_is_reported_even_when_paths_and_counts_match(): void
    {
        $expected = $this->inventory('apps/api/app/Stable.php', 'stable@1', 'a');
        $current = $this->inventory('apps/api/app/Stable.php', 'stable@1', 'b');

        $drift = (new InventoryDriftDetector)->compare($expected, $current);

        $this->assertSame(['apps/api/app/Stable.php'], $drift['changedFiles']);
        $this->assertSame(['stable@1'], $drift['changedSymbols']);
    }

    /** @return array<string, mixed> */
    private function inventory(string $path, string $symbolId, string $hashCharacter): array
    {
        return [
            'scope' => ['command' => 'scope'],
            'files' => [[
                'path' => $path,
                'sha256' => str_repeat($hashCharacter, 64),
                'category' => 'application',
                'language' => 'php',
            ]],
            'symbols' => [[
                'id' => $symbolId,
                'path' => $path,
                'qualifiedName' => 'Stable::run',
                'kind' => 'method',
                'fingerprint' => str_repeat($hashCharacter, 64),
            ]],
        ];
    }
}

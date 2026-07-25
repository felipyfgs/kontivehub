<?php

namespace Tests\Unit\CodeQuality;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tools\CodeQuality\ArtifactSetManager;

class ArtifactSetManagerTest extends TestCase
{
    #[Test]
    public function summary_binds_both_inventory_digests(): void
    {
        $manager = new ArtifactSetManager;
        $api = ['digest' => str_repeat('a', 64), 'summary' => ['files' => 2]];
        $web = ['digest' => str_repeat('b', 64), 'summary' => ['files' => 3]];

        $summary = $manager->summary($api, $web);

        $this->assertSame(['api' => $api['digest'], 'web' => $web['digest']], $summary['inventoryDigests']);
        $this->assertSame(hash('sha256', $api['digest']."\n".$web['digest']), $summary['combinedDigest']);
        $this->assertSame($api['summary'], $summary['api']);
        $this->assertSame($web['summary'], $summary['web']);
    }

    #[Test]
    public function initial_ledger_is_bijective_and_pending(): void
    {
        $inventory = [
            'digest' => str_repeat('c', 64),
            'symbols' => [
                ['id' => 'first'],
                ['id' => 'second'],
            ],
        ];

        $ledger = (new ArtifactSetManager)->pendingLedger($inventory);

        $this->assertSame($inventory['digest'], $ledger['inventoryDigest']);
        $this->assertSame(['first', 'second'], array_column($ledger['entries'], 'symbolId'));
        $this->assertSame(['pending'], array_values(array_unique(array_column($ledger['entries'], 'status'))));
    }
}

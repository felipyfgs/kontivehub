<?php

namespace Tests\Unit\Integra\Sitfis;

use App\Services\Integra\Sitfis\SitfisFlowService;
use Tests\TestCase;

class SitfisProtocolCursorTest extends TestCase
{
    public function test_cursor_for_long_protocol_stays_under_64_chars(): void
    {
        $protocol = str_repeat('B', 200);
        $cursor = SitfisFlowService::cursorForProtocol($protocol);

        $this->assertStringStartsWith('protocol:', $cursor);
        $this->assertLessThanOrEqual(64, strlen($cursor));
        $this->assertSame(25, strlen($cursor)); // protocol: + 16 hex
        $this->assertNotSame('protocol:'.$protocol, $cursor);
    }

    public function test_cursor_for_null_or_empty_is_solicit(): void
    {
        $this->assertSame('solicit', SitfisFlowService::cursorForProtocol(null));
        $this->assertSame('solicit', SitfisFlowService::cursorForProtocol(''));
    }

    public function test_cursor_is_stable_for_same_protocol(): void
    {
        $protocol = '+S7N6c04XNZUVzmxWT7SzpkZA4xeDQC9NuizHIDwRk/3xKKtOnP5VG395dFNJ0OcY';
        $this->assertSame(
            SitfisFlowService::cursorForProtocol($protocol),
            SitfisFlowService::cursorForProtocol($protocol),
        );
    }
}

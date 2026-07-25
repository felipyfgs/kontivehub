<?php

namespace Tests\Unit\MeiAutomation;

use App\Services\MeiAutomation\MeiAutomationHmacSigner;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class MeiAutomationHmacSignerTest extends TestCase
{
    public function test_signs_the_canonical_contract(): void
    {
        config()->set('mei_automation.hmac.key_id', 'laravel');
        config()->set('mei_automation.hmac.secret', 'shared-test-secret');
        Carbon::setTestNow('2026-07-18 12:00:00');

        $body = '{"operation_key":"fixture.health"}';
        $headers = app(MeiAutomationHmacSigner::class)->headers('post', '/v1/jobs', $body);
        $canonical = implode("\n", [
            'POST',
            '/v1/jobs',
            hash('sha256', $body),
            $headers['X-MEI-Timestamp'],
            $headers['X-MEI-Nonce'],
        ]);

        self::assertSame('laravel', $headers['X-MEI-Key-Id']);
        self::assertSame(
            hash_hmac('sha256', $canonical, 'shared-test-secret'),
            $headers['X-MEI-Signature'],
        );
    }
}

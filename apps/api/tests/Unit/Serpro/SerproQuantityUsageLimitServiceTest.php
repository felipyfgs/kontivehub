<?php

namespace Tests\Unit\Serpro;

use App\Enums\SerproEnvironment;
use App\Services\Audit\AuditLogger;
use App\Services\Serpro\SerproQuantityUsageLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerproQuantityUsageLimitServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_trial_without_limit_is_not_configured(): void
    {
        $service = new SerproQuantityUsageLimitService(app(AuditLogger::class));

        $result = $service->evaluate(SerproEnvironment::Trial, null);

        $this->assertFalse($result['allowed']);
        $this->assertSame(
            SerproQuantityUsageLimitService::BLOCK_NOT_CONFIGURED,
            $result['block_reason'],
        );
    }

    public function test_upsert_global_limit_allows_trial_evaluation(): void
    {
        $service = new SerproQuantityUsageLimitService(app(AuditLogger::class));

        $service->upsert(
            environment: SerproEnvironment::Trial,
            cycleStartDay: 1,
            alertPercent: 80,
            globalLimitQuantity: 100,
        );

        $result = $service->evaluate(SerproEnvironment::Trial, null);

        $this->assertTrue($result['allowed']);
        $this->assertNull($result['block_reason']);
        $this->assertSame(100, $result['global_limit']);
    }
}

<?php

namespace Tests\Feature;

use App\Console\Commands\OpsSchedulerHeartbeatCommand;
use App\Services\Ops\ProductionReadinessService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OpsSchedulerHeartbeatCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        @unlink(OpsSchedulerHeartbeatCommand::HEALTHCHECK_FILE);
        Cache::forget(ProductionReadinessService::HEARTBEAT_CACHE_KEY);

        parent::tearDown();
    }

    public function test_command_updates_shared_cache_and_local_healthcheck_file(): void
    {
        @unlink(OpsSchedulerHeartbeatCommand::HEALTHCHECK_FILE);
        Cache::forget(ProductionReadinessService::HEARTBEAT_CACHE_KEY);

        $this->artisan('ops:scheduler-heartbeat')->assertSuccessful();

        $this->assertNotNull(Cache::get(ProductionReadinessService::HEARTBEAT_CACHE_KEY));
        $this->assertFileExists(OpsSchedulerHeartbeatCommand::HEALTHCHECK_FILE);
        $this->assertNotSame('', trim((string) file_get_contents(OpsSchedulerHeartbeatCommand::HEALTHCHECK_FILE)));
    }

    public function test_readiness_rejects_scheduler_heartbeat_from_the_future(): void
    {
        $this->travelTo('2026-08-03 01:00:00');
        Cache::put(
            ProductionReadinessService::HEARTBEAT_CACHE_KEY,
            now()->addMinutes(5)->toIso8601String(),
            now()->addDay(),
        );

        $check = app(ProductionReadinessService::class)->checkSchedulerHeartbeat();

        $this->assertFalse($check['ok']);
        $this->assertSame('future_timestamp', $check['detail']);
    }
}

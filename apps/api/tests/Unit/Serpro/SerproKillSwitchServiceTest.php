<?php

namespace Tests\Unit\Serpro;

use App\Services\Audit\AuditLogger;
use App\Services\Serpro\SerproKillSwitchService;
use Tests\TestCase;

class SerproKillSwitchServiceTest extends TestCase
{
    public function test_config_true_makes_global_kill_switch_active(): void
    {
        config(['serpro.kill_switch' => true]);

        $service = new SerproKillSwitchService(app(AuditLogger::class));

        $this->assertTrue($service->isGlobalActive());
    }

    public function test_config_false_without_durable_state_is_inactive(): void
    {
        config(['serpro.kill_switch' => false]);

        $service = new SerproKillSwitchService(app(AuditLogger::class));

        $this->assertFalse($service->isGlobalActive());
    }
}

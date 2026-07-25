<?php

namespace Tests\Unit\Communication;

use App\Services\Communication\Flows\CommunicationFlowAvailability;
use Tests\TestCase;

final class CommunicationFlowAvailabilityTest extends TestCase
{
    public function test_flows_and_runtime_default_false(): void
    {
        config()->set('communication.flows.enabled', null);
        config()->set('communication.flows.runtime_enabled', null);
        putenv('COMMUNICATION_FLOWS_ENABLED');
        putenv('COMMUNICATION_FLOWS_RUNTIME_ENABLED');
        $_ENV['COMMUNICATION_FLOWS_ENABLED'] = 'false';
        $_ENV['COMMUNICATION_FLOWS_RUNTIME_ENABLED'] = 'false';
        $_SERVER['COMMUNICATION_FLOWS_ENABLED'] = 'false';
        $_SERVER['COMMUNICATION_FLOWS_RUNTIME_ENABLED'] = 'false';

        $this->assertFalse(filter_var(env('COMMUNICATION_FLOWS_ENABLED', false), FILTER_VALIDATE_BOOL));
        $this->assertFalse(filter_var(env('COMMUNICATION_FLOWS_RUNTIME_ENABLED', false), FILTER_VALIDATE_BOOL));

        config([
            'communication.flows.enabled' => filter_var(env('COMMUNICATION_FLOWS_ENABLED', false), FILTER_VALIDATE_BOOL),
            'communication.flows.runtime_enabled' => filter_var(env('COMMUNICATION_FLOWS_RUNTIME_ENABLED', false), FILTER_VALIDATE_BOOL),
        ]);

        $availability = app(CommunicationFlowAvailability::class);
        $this->assertFalse($availability->enabled());
        $this->assertFalse($availability->runtimeEnabled());
    }

    public function test_runtime_requires_both_flags(): void
    {
        config([
            'communication.flows.enabled' => true,
            'communication.flows.runtime_enabled' => false,
        ]);
        $availability = app(CommunicationFlowAvailability::class);
        $this->assertTrue($availability->enabled());
        $this->assertFalse($availability->runtimeEnabled());

        config(['communication.flows.runtime_enabled' => true]);
        $this->assertTrue($availability->runtimeEnabled());
    }
}

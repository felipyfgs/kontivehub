<?php

namespace Tests\Feature\FgtsDigital;

use App\Enums\OfficeRole;
use App\Jobs\Fiscal\ExecuteFgtsDigitalPolicyJob;
use App\Jobs\Fiscal\ExecuteFgtsDigitalRunJob;
use App\Models\Client;
use App\Models\FiscalMonitoringSchedule;
use App\Models\Office;
use App\Models\User;
use App\Services\FgtsDigital\FgtsDigitalPortalService;
use App\Services\FgtsDigital\FgtsDigitalReadinessService;
use App\Services\FgtsDigital\FgtsDigitalScheduleDispatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FgtsDigitalSchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('fgts_digital.driver', 'fixture');
        config()->set('fgts_digital.kill_switch', false);
        config()->set('fgts_digital.mutations_enabled', true);
        config()->set('fgts_digital.scheduler.enabled', true);
        config()->set('fgts_digital.scheduler.emissions_enabled', false);
        config()->set('fgts_digital.scheduler.max_amount_cents', 0);
        config()->set('fgts_digital.runtime.fixtures', base_path('rpa/fgts_digital/fixtures'));
    }

    public function test_query_schedule_is_explicit_and_does_nothing_when_global_opt_in_is_off(): void
    {
        Queue::fake();
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $schedule = $this->schedule($office, $client, 'QUERY_GUIDES');

        config()->set('fgts_digital.scheduler.enabled', false);
        $this->assertSame(
            ['dispatched' => 0, 'blocked' => 0, 'skipped' => 0],
            app(FgtsDigitalScheduleDispatcher::class)->dispatchDue(CarbonImmutable::now()),
        );
        Queue::assertNothingPushed();

        config()->set('fgts_digital.scheduler.enabled', true);
        $result = app(FgtsDigitalScheduleDispatcher::class)->dispatchDue(CarbonImmutable::now());
        $this->assertSame(1, $result['dispatched']);
        Queue::assertPushed(ExecuteFgtsDigitalRunJob::class, 1);
        $this->assertNull($schedule->fresh()->last_skip_reason);
    }

    public function test_scheduled_emission_requires_bounded_policy_and_job_revalidates_before_authorizing(): void
    {
        Queue::fake();
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        $schedule = $this->schedule($office, $client, 'EMIT_GUIDE', [
            'fgts_digital_policy' => [
                'enabled' => true,
                'authorized_by_user_id' => $admin->id,
                'valid_until' => now()->addDay()->toIso8601String(),
                'guide_types' => ['MONTHLY'],
                'max_amount_cents' => 200_000,
            ],
            'fgts_digital_parameters' => [
                'competence_period_key' => '2026-07',
                'guide_type' => 'MONTHLY',
                'amount_cents' => 184_250,
            ],
        ]);

        $disabled = app(FgtsDigitalScheduleDispatcher::class)->dispatchDue(CarbonImmutable::now());
        $this->assertSame(1, $disabled['blocked']);
        $this->assertSame('FGTS_DIGITAL_SCHEDULED_EMISSIONS_DISABLED', $schedule->fresh()->last_skip_reason);
        Queue::assertNothingPushed();

        config()->set('fgts_digital.scheduler.emissions_enabled', true);
        config()->set('fgts_digital.scheduler.max_amount_cents', 200_000);
        $schedule->refresh()->forceFill(['next_run_at' => now()->subMinute()])->save();
        $enabled = app(FgtsDigitalScheduleDispatcher::class)->dispatchDue(CarbonImmutable::now());
        $this->assertSame(1, $enabled['dispatched'], (string) $schedule->fresh()->last_skip_reason);
        Queue::assertPushed(ExecuteFgtsDigitalPolicyJob::class, 1);

        Queue::fake();
        (new ExecuteFgtsDigitalPolicyJob((int) $office->id, (int) $schedule->id))->handle(
            app(FgtsDigitalScheduleDispatcher::class),
            app(FgtsDigitalReadinessService::class),
            app(FgtsDigitalPortalService::class),
        );
        Queue::assertPushed(ExecuteFgtsDigitalRunJob::class, 1);
    }

    /** @param array<string, mixed> $metadata */
    private function schedule(
        Office $office,
        Client $client,
        string $operation,
        array $metadata = [],
    ): FiscalMonitoringSchedule {
        return FiscalMonitoringSchedule::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'FGTS_DIGITAL',
            'service_code' => 'FGTS_DIGITAL',
            'operation_code' => $operation,
            'is_enabled' => true,
            'interval_minutes' => 1440,
            'preferred_minute' => 0,
            'next_run_at' => now()->subMinute(),
            'metadata' => $metadata,
        ]);
    }
}

<?php

namespace Tests\Unit\MeiAutomation;

use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Models\Client;
use App\Models\MeiAutomationAttempt;
use App\Models\Office;
use App\Services\MeiAutomation\MeiAutomationAttemptService;
use App\Services\MeiAutomation\MeiAutomationSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class MeiAutomationSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_sync_lost_when_ephemeral_job_disappears_before_submission(): void
    {
        $attempt = $this->attempt();
        Http::fake(['http://mei.test/v1/jobs/*' => Http::response([], 404)]);

        $synced = app(MeiAutomationSyncService::class)->synchronize($attempt);

        self::assertSame(MeiAutomationStatus::SyncLost, $synced->status);
        self::assertSame('SYNC_LOST', $synced->error_code);
        self::assertNotNull($synced->last_synced_at);
        self::assertNotNull($synced->sync_lost_at);
    }

    public function test_marks_uncertain_when_ephemeral_job_disappears_after_submission(): void
    {
        $attempt = $this->attempt();
        $attempt->forceFill(['submitted_at' => now()])->save();
        Http::fake(['http://mei.test/v1/jobs/*' => Http::response([], 404)]);

        $synced = app(MeiAutomationSyncService::class)->synchronize($attempt);

        self::assertSame(MeiAutomationStatus::Uncertain, $synced->status);
        self::assertSame('SYNC_LOST_AFTER_SUBMISSION', $synced->error_code);
    }

    public function test_persists_running_status_before_redis_ttl(): void
    {
        $attempt = $this->attempt();
        Http::fake(['http://mei.test/v1/jobs/*' => Http::response([
            'id' => $attempt->external_job_id,
            'operation_key' => 'pgmei.dividaativa',
            'status' => 'RUNNING',
            'result' => null,
            'error' => null,
            'artifacts' => [],
            'action_type' => null,
        ])]);

        $synced = app(MeiAutomationSyncService::class)->synchronize($attempt);

        self::assertSame(MeiAutomationStatus::Running, $synced->status);
        self::assertNotNull($synced->last_synced_at);
        self::assertNull($synced->finished_at);
        self::assertLessThan(
            config('mei_automation.result_ttl_seconds'),
            app(MeiAutomationSyncService::class)->pollIntervalSeconds(),
        );
    }

    public function test_rejects_poll_interval_not_lower_than_result_ttl(): void
    {
        config()->set('mei_automation.poll_interval_seconds', 900);
        config()->set('mei_automation.result_ttl_seconds', 900);

        $this->expectException(RuntimeException::class);
        app(MeiAutomationSyncService::class)->assertPollingContract();
    }

    private function attempt(): MeiAutomationAttempt
    {
        config()->set('mei_automation.base_url', 'http://mei.test');
        config()->set('mei_automation.hmac.key_id', 'laravel');
        config()->set('mei_automation.hmac.secret', 'shared-test-secret');
        config()->set('mei_automation.poll_interval_seconds', 10);
        config()->set('mei_automation.result_ttl_seconds', 900);
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $attempt = app(MeiAutomationAttemptService::class)->start(
            $office,
            $client,
            'pgmei.dividaativa',
            MeiProvider::ReceitaPortal,
            'sync:12345678',
            ['cnpj' => '11222333000181', 'calendar_year' => 2026],
        );
        $attempt->forceFill(['external_job_id' => '0f82d5ec-d69f-4b2b-a2d6-b2c52e0e1b92'])->save();

        return $attempt->refresh();
    }
}

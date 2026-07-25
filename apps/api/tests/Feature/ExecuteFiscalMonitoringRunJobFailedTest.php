<?php

namespace Tests\Feature;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class ExecuteFiscalMonitoringRunJobFailedTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_marks_non_terminal_run_as_failed(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();
        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_MEI',
            'service_code' => 'PGMEI',
            'operation_code' => 'MONITOR',
            'operation_key' => 'pgmei.dividaativa',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'pgmei-job-failed:'.fake()->uuid(),
            'status' => FiscalRunStatus::Running,
            'situation' => FiscalSituation::Processing,
            'coverage' => FiscalCoverage::Unknown,
            'mutability' => FiscalMutability::ReadOnly,
            'progress' => ['pgmei_manual' => true],
        ]);

        (new ExecuteFiscalMonitoringRunJob($run->id))->failed(
            new RuntimeException('Could not resolve host: mei'),
        );

        $run->refresh();
        self::assertSame(FiscalRunStatus::Failed, $run->status);
        self::assertSame(FiscalRunResult::Failed, $run->result);
        self::assertSame('JOB_UNHANDLED_EXCEPTION', $run->error_code);
        self::assertNotEmpty($run->error_message);
        self::assertStringContainsString('Could not resolve host: mei', (string) $run->error_message);
    }

    public function test_failed_does_not_rewrite_terminal_run(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();
        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_MEI',
            'service_code' => 'PGMEI',
            'operation_code' => 'MONITOR',
            'operation_key' => 'pgmei.dividaativa',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'pgmei-job-failed-terminal:'.fake()->uuid(),
            'status' => FiscalRunStatus::Completed,
            'result' => FiscalRunResult::Success,
            'situation' => FiscalSituation::UpToDate,
            'coverage' => FiscalCoverage::Full,
            'mutability' => FiscalMutability::ReadOnly,
            'error_code' => null,
            'error_message' => null,
            'progress' => ['pgmei_manual' => true],
        ]);

        (new ExecuteFiscalMonitoringRunJob($run->id))->failed(
            new RuntimeException('late failure'),
        );

        $run->refresh();
        self::assertSame(FiscalRunStatus::Completed, $run->status);
        self::assertSame(FiscalRunResult::Success, $run->result);
        self::assertNull($run->error_code);
    }
}

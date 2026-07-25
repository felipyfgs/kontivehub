<?php

namespace Tests\Feature;

use App\Contracts\FiscalMutationTransport;
use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalMutationStatus;
use App\Enums\OfficeRole;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\FiscalMutationOperation;
use App\Models\Office;
use App\Models\User;
use App\Services\Fiscal\Mutations\FiscalMutationPayload;
use App\Services\Fiscal\Mutations\FiscalMutationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

final class FiscalMutationRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_timeout_becomes_uncertain_and_reconciliation_never_resends_mutation(): void
    {
        $office = Office::factory()->create();
        $actor = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        $client = Client::factory()->forOffice($office)->create();
        Establishment::factory()->forClient($client)->create();
        $payload = [
            'competencies' => ['2026-07'],
            'due_date' => '2026-07-20',
            'output_format' => 'PDF',
        ];
        $operation = FiscalMutationOperation::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'requested_by' => $actor->id,
            'idempotency_key' => (string) Str::uuid(),
            'logical_key' => 'recovery-test',
            'correlation_id' => (string) Str::uuid(),
            'environment' => 'TRIAL',
            'solution_code' => 'INTEGRA_MEI',
            'service_code' => 'PGMEI',
            'operation_code' => 'GERAR_DAS',
            'provider_operation_key' => 'pgmei.gerardaspdf',
            'module_key' => 'simples_mei',
            'competence_period_key' => '2026-07',
            'status' => FiscalMutationStatus::Pending,
            'confirmed_by_user' => true,
            'confirmed_at' => now(),
            'request_sanitized' => $payload,
            'request_payload_encrypted' => $payload,
            'request_payload_digest' => FiscalMutationPayload::digest($payload),
            'eligibility_snapshot' => ['allowed' => true],
            'attempt_count' => 1,
            'preflight_at' => now(),
            'preflight_expires_at' => now()->addMinutes(10),
        ]);

        config([
            'mei_automation.enabled' => true,
            'mei_automation.kill_switch' => false,
            'mei_automation.live_egress_enabled' => true,
            'mei_automation.allow_all_offices' => true,
            'mei_automation.provider_policy.default' => 'portal',
            'mei_automation.provider_policy.operations' => [
                'pgmei.gerardaspdf' => 'portal',
            ],
        ]);
        $transport = new TimeoutThenReconcileTransport;
        $this->app->instance(FiscalMutationTransport::class, $transport);
        $service = app(FiscalMutationService::class);

        $dispatch = new ReflectionMethod($service, 'dispatchTransport');
        $uncertain = $dispatch->invoke($service, $operation, $actor);
        self::assertInstanceOf(FiscalMutationOperation::class, $uncertain);
        self::assertSame(FiscalMutationStatus::UnknownResult, $uncertain->status);
        self::assertSame(1, $transport->executeCalls);

        $same = $dispatch->invoke($service, $uncertain, $actor);
        self::assertSame(FiscalMutationStatus::UnknownResult, $same->status);
        self::assertSame(1, $transport->executeCalls, 'Resultado incerto não pode reenviar a mutação.');

        $confirmed = $service->reconcile($office, $uncertain, $actor);
        self::assertSame(FiscalMutationStatus::Confirmed, $confirmed->status);
        self::assertSame(1, $transport->executeCalls);
        self::assertSame(1, $transport->reconcileCalls);
    }
}

final class TimeoutThenReconcileTransport implements FiscalMutationTransport
{
    public int $executeCalls = 0;

    public int $reconcileCalls = 0;

    public function execute(IntegraRequest $request): IntegraResponse
    {
        $this->executeCalls++;

        throw new \RuntimeException('timeout após envio');
    }

    public function reconcile(IntegraRequest $request): IntegraResponse
    {
        $this->reconcileCalls++;

        return new IntegraResponse(
            success: true,
            httpStatus: 200,
            body: ['status' => 'CONFIRMED', 'protocol' => 'REC-2026-0001'],
            correlationId: 'reconcile-1',
            operationKey: $request->operationKey,
        );
    }
}

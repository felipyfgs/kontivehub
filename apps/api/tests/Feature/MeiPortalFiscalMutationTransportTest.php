<?php

namespace Tests\Feature;

use App\Contracts\SerproFiscalMutationTransport;
use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalMutationStatus;
use App\Enums\FiscalSourceProvenance;
use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\FiscalMutationOperation;
use App\Models\MeiAutomationAttempt;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Models\SerproApiUsageEntry;
use App\Models\SerproContract;
use App\Services\Fiscal\Mutations\FiscalMutationIntegraRequestFactory;
use App\Services\MeiAutomation\MeiDasMutationReconciler;
use App\Services\MeiAutomation\MeiPortalFiscalMutationTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MeiPortalFiscalMutationTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_portal_queued_is_idempotent_and_does_not_consume_serpro(): void
    {
        [$office, $client, $mutation] = $this->mutation();
        $this->enablePortal();
        $this->enableSerproFallback($office);
        Queue::fake();
        Http::fake([
            'http://mei:8080/v1/jobs' => Http::response([
                'id' => 'a9ba5651-754c-43f6-b9cc-3ce2f35c203e',
                'operation_key' => 'pgmei.gerardaspdf',
                'status' => 'QUEUED',
                'result' => null,
                'error' => null,
                'artifacts' => [],
                'action_type' => 'MUTATION',
            ], 202),
        ]);
        $request = $this->request($office, $client, $mutation);
        $transport = app(MeiPortalFiscalMutationTransport::class);

        self::assertTrue($transport->execute($request)->isStillProcessing());
        self::assertTrue($transport->execute($request)->isStillProcessing());

        self::assertSame(1, MeiAutomationAttempt::query()->withoutGlobalScopes()->count());
        $attempt = MeiAutomationAttempt::query()->withoutGlobalScopes()->firstOrFail();
        self::assertSame($mutation->id, $attempt->fiscal_mutation_operation_id);
        self::assertSame(MeiAutomationStatus::Queued, $attempt->status);
        self::assertSame(0, SerproApiUsageEntry::query()->withoutGlobalScopes()->count());
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->data()['input']['due_date'] === '2026-07-20');
    }

    public function test_success_confirms_portal_without_serpro_and_uncertain_never_resends(): void
    {
        [$office, $client, $confirmedMutation] = $this->mutation();
        $confirmedAttempt = $this->attempt(
            $office,
            $client,
            $confirmedMutation,
            MeiAutomationStatus::Succeeded,
            submitted: true,
        );
        $reconciler = app(MeiDasMutationReconciler::class);

        $reconciler->reconcile($confirmedAttempt);
        self::assertSame(FiscalMutationStatus::Confirmed, $confirmedMutation->refresh()->status);
        self::assertSame(0, SerproApiUsageEntry::query()->withoutGlobalScopes()->count());

        [, , $uncertainMutation] = $this->mutation($office, $client);
        $uncertainAttempt = $this->attempt(
            $office,
            $client,
            $uncertainMutation,
            MeiAutomationStatus::Uncertain,
            submitted: true,
        );
        Http::fake();

        $reconciler->reconcile($uncertainAttempt);
        $reconciler->reconcile($uncertainAttempt->refresh());

        self::assertSame(FiscalMutationStatus::UnknownResult, $uncertainMutation->refresh()->status);
        self::assertSame('PORTAL_RESULT_UNCERTAIN', $uncertainMutation->result_code);
        self::assertSame(0, SerproApiUsageEntry::query()->withoutGlobalScopes()->count());
        Http::assertNothingSent();
    }

    public function test_portal_request_does_not_require_serpro_credentials(): void
    {
        [, , $mutation] = $this->mutation();
        $this->enablePortal();

        $request = app(FiscalMutationIntegraRequestFactory::class)->make($mutation);

        self::assertSame($request->contributorCnpj, $request->contractorCnpj);
        self::assertSame($request->contributorCnpj, $request->authorIdentity);
    }

    public function test_pre_submission_transport_failure_falls_back_once_to_serpro(): void
    {
        [$office, $client, $mutation] = $this->mutation();
        $this->enablePortal();
        $this->enableSerproFallback($office);
        $serpro = new FakeSerproFiscalMutationTransport;
        $this->app->instance(SerproFiscalMutationTransport::class, $serpro);
        Http::fake([
            'http://mei:8080/v1/jobs' => Http::response(['message' => 'offline'], 503),
        ]);

        $response = app(MeiPortalFiscalMutationTransport::class)
            ->execute($this->request($office, $client, $mutation));

        self::assertTrue($response->success);
        self::assertSame(1, $serpro->executeCalls);
        self::assertSame('PORTAL_UNAVAILABLE', MeiAutomationAttempt::query()
            ->withoutGlobalScopes()
            ->firstOrFail()
            ->fallback_reason);
    }

    public function test_pre_submission_connection_failure_falls_back_once_to_serpro(): void
    {
        [$office, $client, $mutation] = $this->mutation();
        $this->enablePortal();
        $this->enableSerproFallback($office);
        $serpro = new FakeSerproFiscalMutationTransport;
        $this->app->instance(SerproFiscalMutationTransport::class, $serpro);
        Http::fake(function () {
            throw new ConnectionException('Could not resolve host: mei');
        });

        $response = app(MeiPortalFiscalMutationTransport::class)
            ->execute($this->request($office, $client, $mutation));

        self::assertTrue($response->success);
        self::assertSame(1, $serpro->executeCalls);
        self::assertSame('PORTAL_UNAVAILABLE', MeiAutomationAttempt::query()
            ->withoutGlobalScopes()
            ->firstOrFail()
            ->fallback_reason);
    }

    /** @return array{Office, Client, FiscalMutationOperation} */
    private function mutation(?Office $office = null, ?Client $client = null): array
    {
        $office ??= Office::factory()->create();
        $client ??= Client::factory()->forOffice($office)->create();
        if (! Establishment::query()->withoutGlobalScopes()->where('client_id', $client->id)->exists()) {
            Establishment::factory()->forClient($client)->create();
        }
        $key = (string) Str::uuid();
        $mutation = FiscalMutationOperation::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'idempotency_key' => $key,
            'logical_key' => 'mei-das|'.$key,
            'correlation_id' => (string) Str::uuid(),
            'environment' => 'TRIAL',
            'solution_code' => 'INTEGRA_MEI',
            'service_code' => 'PGMEI',
            'operation_code' => 'GERAR_DAS',
            'module_key' => 'simples_mei',
            'competence_period_key' => '2025-01',
            'status' => FiscalMutationStatus::Sent,
            'request_sanitized' => [
                'competencies' => ['2025-01'],
                'due_date' => '2026-07-20',
                'output_format' => 'PDF',
            ],
            'result_code' => 'MEI_PORTAL_PROCESSING',
            'attempt_count' => 1,
            'sent_at' => now(),
        ]);

        return [$office, $client, $mutation];
    }

    private function request(
        Office $office,
        Client $client,
        FiscalMutationOperation $mutation,
    ): IntegraRequest {
        return new IntegraRequest(
            officeId: (int) $office->id,
            clientId: (int) $client->id,
            environment: 'TRIAL',
            contractorCnpj: '04252011000110',
            authorIdentity: '52998224725',
            contributorCnpj: '04252011000110',
            operationKey: 'pgmei.gerardaspdf',
            payload: [
                'mutation_operation_id' => $mutation->id,
                'competencies' => ['2025-01'],
                'due_date' => '2026-07-20',
                'output_format' => 'PDF',
            ],
            idempotencyKey: $mutation->idempotency_key,
            correlationId: $mutation->correlation_id,
            isMutating: true,
            solutionCode: 'INTEGRA_MEI',
            serviceCode: 'PGMEI',
            operationCode: 'GERAR_DAS',
        );
    }

    private function attempt(
        Office $office,
        Client $client,
        FiscalMutationOperation $mutation,
        MeiAutomationStatus $status,
        bool $submitted,
    ): MeiAutomationAttempt {
        return MeiAutomationAttempt::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'fiscal_mutation_operation_id' => $mutation->id,
            'external_job_id' => (string) Str::uuid(),
            'operation_key' => 'pgmei.gerardaspdf',
            'provider' => MeiProvider::ReceitaPortal,
            'status' => $status,
            'idempotency_key' => 'mutation:'.hash('sha256', $mutation->idempotency_key),
            'request_fingerprint' => hash('sha256', $mutation->idempotency_key),
            'submitted_at' => $submitted ? now() : null,
            'finished_at' => now(),
        ]);
    }

    private function enablePortal(): void
    {
        config([
            'mei_automation.enabled' => true,
            'mei_automation.kill_switch' => false,
            'mei_automation.live_egress_enabled' => true,
            'mei_automation.fixture_enabled' => false,
            'mei_automation.allow_all_offices' => true,
            'mei_automation.provider_policy.default' => 'portal_then_serpro',
            'mei_automation.provider_policy.operations' => [
                'pgmei.gerardaspdf' => 'portal_then_serpro',
                'pgmei.gerardascodbarra' => 'portal_then_serpro',
                'pgmei.dividaativa' => 'portal_then_serpro',
                'dasnsimei.consultimadecrec' => 'portal_then_serpro',
            ],
            'mei_automation.hmac.secret' => str_repeat('s', 32),
            'mei_automation.base_url' => 'http://mei:8080',
        ]);
    }

    private function enableSerproFallback(Office $office): void
    {
        SerproContract::query()->create([
            'environment' => 'TRIAL',
            'status' => 'ACTIVE',
            'contractor_cnpj' => '04252011000110',
        ]);
        OfficeSerproAuthorization::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'environment' => 'TRIAL',
            'status' => 'TERM_VALID',
            'author_identity_type' => 'CPF',
            'author_identity' => '52998224725',
            'certificate_mode' => 'EXTERNAL_SIGNATURE',
        ]);
    }
}

final class FakeSerproFiscalMutationTransport implements SerproFiscalMutationTransport
{
    public int $executeCalls = 0;

    public function execute(IntegraRequest $request): IntegraResponse
    {
        $this->executeCalls++;

        return new IntegraResponse(
            success: true,
            httpStatus: 200,
            body: ['status' => 'CONFIRMED'],
            operationKey: $request->operationKey,
            sourceProvenance: FiscalSourceProvenance::SerproReal->value,
        );
    }

    public function reconcile(IntegraRequest $request): IntegraResponse
    {
        return $this->execute($request);
    }
}

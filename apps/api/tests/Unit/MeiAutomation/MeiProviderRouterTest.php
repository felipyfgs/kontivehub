<?php

namespace Tests\Unit\MeiAutomation;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalTrigger;
use App\Enums\MeiProvider;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Tenant;
use App\Services\Fiscal\SimplesMei\SimplesMeiCatalog;
use App\Services\MeiAutomation\MeiAutomationAttemptRepository;
use App\Services\MeiAutomation\MeiAutomationMetadataSanitizer;
use App\Services\MeiAutomation\MeiPortalResultValidator;
use App\Services\MeiAutomation\MeiProviderPolicy;
use App\Services\MeiAutomation\MeiProviderRouter;
use App\Services\MeiAutomation\Providers\MeiOperationProvider;
use App\Services\MeiAutomation\Providers\MeiProviderOutcome;
use Tests\TestCase;

class MeiProviderRouterTest extends TestCase
{
    public function test_feature_off_calls_only_existing_serpro_provider(): void
    {
        $portal = new StubMeiProvider($this->success(MeiProvider::ReceitaPortal));
        $serpro = new StubMeiProvider($this->success(MeiProvider::Serpro));
        $router = $this->router($serpro, $portal, enabled: false);

        $router->execute($this->request());

        self::assertSame(0, $portal->calls);
        self::assertSame(1, $serpro->calls);
    }

    public function test_portal_success_does_not_call_serpro(): void
    {
        $portal = new StubMeiProvider($this->success(MeiProvider::ReceitaPortal));
        $serpro = new StubMeiProvider($this->success(MeiProvider::Serpro));
        $router = $this->router($serpro, $portal);

        $router->execute($this->request());

        self::assertSame(1, $portal->calls);
        self::assertSame(0, $serpro->calls);
    }

    public function test_classified_pre_submission_drift_falls_back_to_serpro(): void
    {
        $portal = new StubMeiProvider(new MeiProviderOutcome(
            result: FiscalAdapterResult::failed('drift', 'PORTAL_DRIFT'),
            provider: MeiProvider::ReceitaPortal,
            fallbackEligible: true,
            submitted: false,
            fallbackReason: 'PORTAL_DRIFT',
        ));
        $serpro = new StubMeiProvider($this->success(MeiProvider::Serpro));
        $router = $this->router($serpro, $portal);

        $router->execute($this->request());

        self::assertSame(1, $portal->calls);
        self::assertSame(1, $serpro->calls);
    }

    public function test_unclassified_or_submitted_failure_never_falls_back(): void
    {
        foreach ([
            new MeiProviderOutcome(
                result: FiscalAdapterResult::failed('validation', 'FISCAL_VALIDATION_ERROR'),
                provider: MeiProvider::ReceitaPortal,
                fallbackEligible: true,
                submitted: false,
                fallbackReason: 'FISCAL_VALIDATION_ERROR',
            ),
            new MeiProviderOutcome(
                result: FiscalAdapterResult::failed('uncertain', 'PORTAL_RESULT_UNCERTAIN'),
                provider: MeiProvider::ReceitaPortal,
                fallbackEligible: true,
                submitted: true,
                fallbackReason: 'PORTAL_DRIFT',
            ),
        ] as $outcome) {
            $portal = new StubMeiProvider($outcome);
            $serpro = new StubMeiProvider($this->success(MeiProvider::Serpro));
            $this->router($serpro, $portal)->execute($this->request());
            self::assertSame(0, $serpro->calls);
        }
    }

    private function router(
        MeiOperationProvider $serpro,
        MeiOperationProvider $portal,
        bool $enabled = true,
    ): MeiProviderRouter {
        config([
            'mei_automation.enabled' => $enabled,
            'mei_automation.kill_switch' => false,
            'mei_automation.live_egress_enabled' => true,
            'mei_automation.allow_all_tenants' => true,
            'mei_automation.provider_policy.default' => 'portal_then_serpro',
            'mei_automation.provider_policy.operations' => [],
        ]);
        $definition = SimplesMeiCatalog::find('INTEGRA_MEI', 'PGMEI', 'CONSULTAR');
        self::assertNotNull($definition);

        return new MeiProviderRouter(
            definition: $definition,
            serpro: $serpro,
            portal: $portal,
            policy: new MeiProviderPolicy,
            attempts: new MeiAutomationAttemptRepository(
                new MeiAutomationMetadataSanitizer,
                new MeiPortalResultValidator,
            ),
        );
    }

    private function request(): FiscalAdapterRequest
    {
        $tenant = new Tenant;
        $tenant->id = 7;
        $client = new Client;
        $client->id = 11;
        $client->tenant_id = 7;
        $run = new FiscalMonitoringRun;
        $run->forceFill([
            'id' => 13,
            'tenant_id' => 7,
            'client_id' => 11,
            'system_code' => 'INTEGRA_MEI',
            'service_code' => 'PGMEI',
            'operation_code' => 'CONSULTAR',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'router:12345678',
        ]);

        return new FiscalAdapterRequest(
            tenant: $tenant,
            client: $client,
            run: $run,
            systemCode: 'INTEGRA_MEI',
            serviceCode: 'PGMEI',
            operationCode: 'CONSULTAR',
            trigger: FiscalTrigger::Manual,
        );
    }

    private function success(MeiProvider $provider): MeiProviderOutcome
    {
        return new MeiProviderOutcome(
            result: FiscalAdapterResult::unsupported('fixture'),
            provider: $provider,
        );
    }
}

final class StubMeiProvider implements MeiOperationProvider
{
    public int $calls = 0;

    public function __construct(
        private readonly MeiProviderOutcome $outcome,
    ) {}

    public function execute(FiscalAdapterRequest $request, string $operationKey): MeiProviderOutcome
    {
        $this->calls++;

        return $this->outcome;
    }
}

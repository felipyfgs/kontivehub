<?php

namespace Tests\Unit\Fiscal\Mutations;

use App\Enums\FiscalMutationDenialCode;
use App\Enums\SerproEnvironment;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Fiscal\Mutations\FiscalMutationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class FiscalMutationPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_kill_switch_denies_mutation_with_stable_code(): void
    {
        config([
            'fiscal.kill_switch' => false,
            'features.mutating.kill_switch' => false,
            'fiscal_mutations.kill_switch' => true,
            'fiscal_mutations.enabled' => true,
            'fiscal_mutations.operations' => [
                'INTEGRA_MEI.PGMEI.GERAR_DAS' => [
                    'enabled' => true,
                    'allow_all_tenants' => true,
                ],
            ],
        ]);

        [$tenant, $client, $admin] = $this->tenant(TenantRole::TenantAdmin);

        $result = app(FiscalMutationPolicy::class)->evaluate(
            tenant: $tenant,
            client: $client,
            user: $admin,
            solutionCode: 'INTEGRA_MEI',
            serviceCode: 'PGMEI',
            operationCode: 'GERAR_DAS',
            environment: SerproEnvironment::Trial,
            competencePeriodKey: '2025-01',
            module: 'simples_mei',
            options: [
                'require_password' => false,
                'require_confirmation' => false,
                'confirmed' => true,
                'skip_anti_repeat' => true,
                'skip_uncertain_check' => true,
                'operation_key' => 'pgmei.gerardaspdf',
            ],
        );

        $this->assertFalse($result->allowed);
        $codes = array_map(
            static fn (FiscalMutationDenialCode $code): string => $code->value,
            $result->codes,
        );
        $this->assertContains(FiscalMutationDenialCode::KillSwitch->value, $codes);
    }

    public function test_missing_password_confirmation_denies_mutation(): void
    {
        config([
            'fiscal.kill_switch' => false,
            'features.mutating.kill_switch' => false,
            'fiscal_mutations.kill_switch' => false,
            'fiscal_mutations.enabled' => true,
            'fiscal_mutations.operations' => [
                'INTEGRA_MEI.PGMEI.GERAR_DAS' => [
                    'enabled' => true,
                    'allow_all_tenants' => true,
                ],
            ],
        ]);

        [$tenant, $client, $admin] = $this->tenant(TenantRole::TenantAdmin);

        $result = app(FiscalMutationPolicy::class)->evaluate(
            tenant: $tenant,
            client: $client,
            user: $admin,
            solutionCode: 'INTEGRA_MEI',
            serviceCode: 'PGMEI',
            operationCode: 'GERAR_DAS',
            environment: SerproEnvironment::Trial,
            competencePeriodKey: '2025-01',
            module: 'simples_mei',
            options: [
                'require_password' => true,
                'require_confirmation' => false,
                'confirmed' => true,
                'skip_anti_repeat' => true,
                'skip_uncertain_check' => true,
                'operation_key' => 'pgmei.gerardaspdf',
            ],
        );

        $this->assertFalse($result->allowed);
        $codes = array_map(
            static fn (FiscalMutationDenialCode $code): string => $code->value,
            $result->codes,
        );
        $this->assertContains(FiscalMutationDenialCode::PasswordConfirmationRequired->value, $codes);
    }

    /** @return array{Tenant, Client, User} */
    private function tenant(TenantRole $role): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, $role)->create();
        $client = Client::factory()->forTenant($tenant)->create();

        return [$tenant, $client, $user];
    }
}

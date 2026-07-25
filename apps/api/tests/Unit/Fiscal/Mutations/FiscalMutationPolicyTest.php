<?php

namespace Tests\Unit\Fiscal\Mutations;

use App\Enums\FiscalMutationDenialCode;
use App\Enums\OfficeRole;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\Office;
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
                    'allow_all_offices' => true,
                ],
            ],
        ]);

        [$office, $client, $admin] = $this->tenant(OfficeRole::Admin);

        $result = app(FiscalMutationPolicy::class)->evaluate(
            office: $office,
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
                    'allow_all_offices' => true,
                ],
            ],
        ]);

        [$office, $client, $admin] = $this->tenant(OfficeRole::Admin);

        $result = app(FiscalMutationPolicy::class)->evaluate(
            office: $office,
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
            ],
        );

        $this->assertFalse($result->allowed);
        $codes = array_map(
            static fn (FiscalMutationDenialCode $code): string => $code->value,
            $result->codes,
        );
        $this->assertContains(FiscalMutationDenialCode::PasswordConfirmationRequired->value, $codes);
    }

    /** @return array{Office, Client, User} */
    private function tenant(OfficeRole $role): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, $role)->create();
        $client = Client::factory()->forOffice($office)->create();

        return [$office, $client, $user];
    }
}

<?php

namespace Database\Seeders\Development;

use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproContractStatus;
use App\Enums\SerproEnvironment;
use App\Enums\TermoAuthorizationState;
use App\Models\SerproContract;
use App\Models\Tenant;
use App\Models\TenantSerproAuthorization;
use Illuminate\Database\Seeder;
use LogicException;

/**
 * Marca plataforma e autorizações SERPRO como ativas no ambiente local.
 * Com FISCAL_PROFILE=dev todo egresso usa o driver Fixture (sem rede);
 * estes registros apenas destravam os resumos operacionais exibidos na UI.
 */
final class DevelopmentSerproSeeder extends Seeder
{
    private const FIXTURE_CNPJ = '11222333000181';

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new LogicException('DevelopmentSerproSeeder permitido somente em local.');
        }

        $environment = SerproEnvironment::tryFrom(strtoupper((string) config('serpro.default_environment', 'TRIAL')))
            ?? SerproEnvironment::Trial;

        SerproContract::query()->updateOrCreate(
            [
                'environment' => $environment->value,
                'status' => SerproContractStatus::Active->value,
            ],
            [
                'contractor_cnpj' => self::FIXTURE_CNPJ,
                'contractor_name' => 'Contrato dev (fixture, sem egresso real)',
                'activated_at' => now(),
                'health_status' => 'OK',
                'health_message' => 'Fixture local — sem chamadas externas.',
            ],
        );

        foreach (Tenant::query()->orderBy('id')->get(['id']) as $tenant) {
            $authorization = TenantSerproAuthorization::query()
                ->withoutGlobalScopes()
                ->firstOrNew([
                    'tenant_id' => $tenant->id,
                    'environment' => $environment->value,
                ]);

            $authorization->forceFill([
                'status' => SerproAuthorizationStatus::TermValid,
                'author_identity_type' => AuthorIdentityType::Cnpj,
                'author_identity' => self::FIXTURE_CNPJ,
                'author_name' => 'Autor dev (fixture)',
                'certificate_mode' => $authorization->certificate_mode ?? AuthorCertificateMode::ExternalSignature,
                'termo_authorization_state' => TermoAuthorizationState::LocalValidated,
                'termo_valid_from' => now()->subDay(),
                'termo_valid_to' => now()->addYear(),
                'termo_signed_by' => 'Seed dev',
                'termo_uploaded_at' => now(),
            ])->save();
        }

        $this->command?->info('DevelopmentSerproSeeder concluído: contrato ACTIVE + autorizações TERM_VALID (fixture, sem egresso).');
    }
}

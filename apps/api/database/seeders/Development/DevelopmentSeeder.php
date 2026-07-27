<?php

namespace Database\Seeders\Development;

use App\Enums\PlatformRole;
use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantLifecycleStatus;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Establishment;
use App\Models\PlatformMembership;
use App\Models\Tenant;
use App\Models\TenantInstitutionalProfile;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

final class DevelopmentSeeder extends Seeder
{
    public const PLATFORM_SLUG = 'plataforma';

    public const PLATFORM_CNPJ = '65396736000176';

    public const TENANT_SLUG = 'contador';

    public const TENANT_CNPJ = '48123272000105';

    public const CLIENT_CNPJ = '30288513000100';

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new LogicException('DevelopmentSeeder permitido somente em local.');
        }

        DB::transaction(function (): void {
            $platform = $this->upsertTenant(
                self::PLATFORM_SLUG,
                '65.396.736 FELIPE GALVAO DE SOUZA',
                self::PLATFORM_CNPJ,
                'felipegalvaocontador@gmail.com',
                '(99) 9190-0207',
                SubscriptionPlan::Enterprise,
            );
            $tenant = $this->upsertTenant(
                self::TENANT_SLUG,
                'G A SILVA ASSESSORIA CONTABIL',
                self::TENANT_CNPJ,
                'gustavo8araujo@gmail.com',
                '(99) 8234-9654/ (0000) 0000-0000',
                SubscriptionPlan::Professional,
            );

            $this->upsertPlatformAdministrator($platform);
            $this->upsertTenantAdministrator($tenant);
            $this->upsertClient($tenant);
        });

        $this->command?->info('DevelopmentSeeder concluído: tenants=2 users=2 clients=1 establishments=1 contacts=1.');
    }

    private function upsertTenant(
        string $slug,
        string $legalName,
        string $cnpj,
        string $email,
        string $phone,
        SubscriptionPlan $plan,
    ): Tenant {
        $tenant = Tenant::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $legalName,
                'is_active' => true,
                'lifecycle_status' => TenantLifecycleStatus::Active,
                'timezone' => 'America/Sao_Paulo',
                'deadline_timezone' => 'America/Sao_Paulo',
                'communication_enabled' => false,
            ],
        );

        TenantInstitutionalProfile::query()->withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'cnpj' => $cnpj,
                'legal_name' => $legalName,
                'institutional_email' => $email,
                'institutional_phone' => $phone,
            ],
        );

        $limits = $plan->defaultLimits();
        $commercial = $plan->commercialEntitlements();
        TenantSubscription::query()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'plan' => $plan,
                'status' => SubscriptionStatus::Active,
                'starts_at' => '2026-01-01 00:00:00',
                'current_period_starts_at' => '2026-01-01 00:00:00',
                'current_period_ends_at' => '2099-12-31 23:59:59',
                'monthly_api_quota' => $limits['monthly_api_quota'],
                'commercial_monitor_units' => $commercial['commercial_monitor_units'],
                'max_clients' => $limits['max_clients'],
                'negotiated_client_limit' => null,
                'max_users' => $limits['max_users'],
                'limits' => [...$limits, ...$commercial],
                'notes' => 'Assinatura de desenvolvimento.',
            ],
        );

        return $tenant;
    }

    private function upsertPlatformAdministrator(Tenant $platform): void
    {
        $email = (string) config('development_data.platform.admin_email');
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => 'Felipe Galvão de Souza',
            'password' => (string) config('development_data.platform.admin_password'),
            'email_verified_at' => '2026-01-01 00:00:00',
            'is_active' => true,
            'password_change_required' => false,
            'selected_tenant_id' => null,
        ])->save();

        $foreignOwnerExists = PlatformMembership::query()
            ->where('role', PlatformRole::PlatformAdmin)
            ->where('user_id', '!=', $user->id)
            ->exists();
        if ($foreignOwnerExists) {
            throw new RuntimeException('DEVELOPMENT_PLATFORM_OWNER_CONFLICT');
        }

        PlatformMembership::query()->updateOrCreate(
            ['user_id' => $user->id, 'role' => PlatformRole::PlatformAdmin],
            ['is_active' => true, 'default_tenant_id' => $platform->id],
        );
    }

    private function upsertTenantAdministrator(Tenant $tenant): void
    {
        $user = User::query()->firstOrNew([
            'email' => (string) config('development_data.tenant.admin_email'),
        ]);
        $user->forceFill([
            'name' => 'Administrador do Escritório',
            'password' => (string) config('development_data.tenant.admin_password'),
            'email_verified_at' => '2026-01-01 00:00:00',
            'is_active' => true,
            'password_change_required' => false,
            'selected_tenant_id' => $tenant->id,
        ])->save();

        TenantMembership::query()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'user_id' => $user->id],
            [
                'role' => TenantRole::TenantAdmin,
                'permission_profile_id' => null,
                'authorization_version' => 1,
                'is_active' => true,
            ],
        );
    }

    private function upsertClient(Tenant $tenant): void
    {
        $client = Client::query()->withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'root_cnpj' => '30288513'],
            [
                'legal_name' => 'AUTO CENTER TECH AUTOMOTIVO LTDA',
                'display_name' => 'AUTO CENTER TECH AUTOMOTIVO',
                'legal_nature_code' => '2062',
                'legal_nature_name' => 'Sociedade Empresária Limitada',
                'company_size_code' => 'ME',
                'company_size_name' => 'Microempresa',
                'is_active' => true,
                'registration_source' => RegistrationSource::Manual,
                'registration_refreshed_at' => '2021-12-26 11:25:45',
            ],
        );

        Establishment::query()->withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'cnpj' => self::CLIENT_CNPJ],
            [
                'client_id' => $client->id,
                'trade_name' => 'AUTO CENTER TECH AUTOMOTIVO',
                'is_headquarters' => true,
                'is_active' => true,
                'registration_status' => RegistrationStatus::Active,
                'registration_status_at' => '2018-04-24',
                'activity_started_at' => '2018-04-24',
                'main_cnae_code' => '4520001',
                'main_cnae_name' => 'Serviços de manutenção e reparação mecânica de veículos automotores',
                'secondary_cnaes' => [[
                    'code' => '4530703',
                    'name' => 'Comércio a varejo de peças e acessórios novos para veículos automotores',
                ]],
                'address_postal_code' => '65911321',
                'address_street' => 'R AIMORES',
                'address_number' => '07',
                'address_complement' => 'QUADRA43 LOTE 07',
                'address_district' => 'PARQUE DAS ESTRELAS',
                'address_city' => 'IMPERATRIZ',
                'address_state' => 'MA',
                'address_country' => 'BR',
                'public_email' => null,
                'public_phone' => '(99) 9152-5397',
                'capture_enabled' => true,
                'registration_source' => RegistrationSource::Manual,
                'registration_refreshed_at' => '2021-12-26 11:25:45',
            ],
        );

        ClientContact::query()->withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'client_id' => $client->id],
            [
                'name' => 'Auto Center Tech Automotivo',
                'role' => 'Contato principal',
                'email' => null,
                'phone' => '(99) 9152-5397',
                'is_whatsapp' => true,
                'is_primary' => true,
                'receives_alerts' => true,
                'is_active' => true,
            ],
        );
    }
}

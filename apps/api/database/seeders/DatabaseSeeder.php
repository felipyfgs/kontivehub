<?php

namespace Database\Seeders;

use App\Enums\OfficeRole;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\Office;
use App\Models\OfficeMembership;
use App\Models\OfficeSubscription;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use LogicException;

/**
 * Seed **normal de desenvolvimento** — só exemplos sintéticos.
 *
 * Não carrega dados reais de `.local/dados/` (Felipe, Gustavo, G A SILVA, AUTO CENTER).
 * Para piloto prático use {@see PilotSeeder}:
 *   php artisan migrate:fresh --seeder=PilotSeeder
 *
 * Login dev (senha `password`):
 * - plataforma@example.com → PLATFORM_ADMIN (aba Admin/SERPRO)
 * - admin@ / operador@ / viewer@ → office contador (quando SEED_DEMO_OFFICE_USERS)
 *
 * Flags opcionais (local; em testing sempre ON):
 * - SEED_DEMO_OFFICE_USERS
 * - SEED_FISCAL_DEMO
 * - SEED_WORK_DEMO
 */
class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Slug do escritório contábil de exemplo (não é o piloto real). */
    public const ACCOUNTANT_OFFICE_SLUG = 'contador';

    public const ACCOUNTANT_OFFICE_NAME = 'Escritório Contábil Demo';

    /** @var list<string> */
    private const LEGACY_SENTINEL_SLUGS = [
        'demo-sentinel',
        'demo-work-sentinel',
    ];

    /** @var list<string> */
    private const LEGACY_DEMO_EMAILS = [
        'operador@example.com',
        'admin@example.com',
        'viewer@example.com',
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('O seeder de demonstração só pode ser executado em local/testing.');
        }

        $this->retireLegacySentinelOffices();

        $platformOffice = Office::query()->updateOrCreate(
            ['slug' => PlatformAdminDemoSeeder::OFFICE_SLUG],
            ['name' => PlatformAdminDemoSeeder::OFFICE_NAME, 'is_active' => true],
        );

        $contadorOffice = Office::query()->updateOrCreate(
            ['slug' => self::ACCOUNTANT_OFFICE_SLUG],
            ['name' => self::ACCOUNTANT_OFFICE_NAME, 'is_active' => true],
        );

        $this->retireDuplicateDemoOffice($contadorOffice);

        $this->ensureActiveSubscription($platformOffice);
        $this->ensureActiveSubscription($contadorOffice);

        // PLATFORM_ADMIN de exemplo — permanece ativo (não vira Felipe).
        $this->call(PlatformAdminDemoSeeder::class);
        $this->pointPlatformAdminDefaultOffice($platformOffice);
        $this->ensurePlatformAdminTenantMembership($platformOffice);

        if ($this->shouldSeedDemoOfficeUsers()) {
            $this->seedDemoOfficeUsers($contadorOffice);
        } elseif (app()->environment('local')) {
            $this->deactivateLegacyDemoUsers();
        }

        // NÃO chama LocalSerproSmokeSeeder / PilotSeeder aqui.
        // Piloto real: php artisan db:seed --class=PilotSeeder

        if ($this->shouldSeedFiscalDemo()) {
            $this->ensureFiscalDemoOffice();
            $this->call(FiscalMonitoringDemoSeeder::class);
        }

        if ($this->shouldSeedWorkDemo()) {
            $workDemoOffice = $this->ensureWorkDemoOffice();
            $this->seedDemoOfficeUsers($workDemoOffice);
            $this->call(OperationalWorkDemoSeeder::class);
        }
    }

    /**
     * Desativa office legado slug=demo se for outro registro que o contador.
     */
    private function retireDuplicateDemoOffice(Office $contador): void
    {
        $legacy = Office::query()
            ->where('slug', 'demo')
            ->whereKeyNot($contador->id)
            ->first();

        if ($legacy === null) {
            return;
        }

        $legacyMemberships = OfficeMembership::query()
            ->where('office_id', $legacy->id)
            ->orderBy('id')
            ->get();

        foreach ($legacyMemberships as $membership) {
            $existsOnContador = OfficeMembership::query()
                ->where('office_id', $contador->id)
                ->where('user_id', $membership->user_id)
                ->exists();

            if ($existsOnContador) {
                $membership->delete();

                continue;
            }

            $membership->office_id = $contador->id;
            $membership->save();
        }

        User::query()
            ->where('selected_office_id', $legacy->id)
            ->update(['selected_office_id' => $contador->id]);

        $hasClients = Client::query()->where('office_id', $legacy->id)->exists();
        if (app()->environment('local') && ! $hasClients) {
            OfficeSubscription::query()->where('office_id', $legacy->id)->delete();
            OfficeMembership::query()->where('office_id', $legacy->id)->delete();
            $legacy->delete();

            return;
        }

        $legacy->forceFill([
            'is_active' => false,
            'name' => str_contains($legacy->name, '(legado)')
                ? $legacy->name
                : $legacy->name.' (legado)',
        ])->save();
    }

    private function shouldSeedDemoOfficeUsers(): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        return filter_var(env('SEED_DEMO_OFFICE_USERS', false), FILTER_VALIDATE_BOOL);
    }

    private function shouldSeedFiscalDemo(): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        return filter_var(env('SEED_FISCAL_DEMO', false), FILTER_VALIDATE_BOOL);
    }

    /**
     * O seeder fiscal usa o slug configurado, que pode não ser o escritório
     * contábil padrão. Garante a pré-condição antes de delegar às fixtures.
     */
    private function ensureFiscalDemoOffice(): Office
    {
        $slug = trim((string) config('fiscal_demo.office_slug', self::ACCOUNTANT_OFFICE_SLUG));

        if ($slug === '') {
            throw new LogicException('fiscal_demo.office_slug não pode ser vazio ao carregar fixtures fiscais.');
        }

        $office = Office::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => 'Escritório Fiscal Demo', 'is_active' => true],
        );

        if (! $office->is_active) {
            $office->forceFill(['is_active' => true])->save();
        }

        $this->ensureActiveSubscription($office);

        return $office;
    }

    private function shouldSeedWorkDemo(): bool
    {
        if (app()->environment('testing')) {
            return true;
        }

        return filter_var(env('SEED_WORK_DEMO', false), FILTER_VALIDATE_BOOL);
    }

    /** Garante o tenant e as pré-condições do seeder operacional configurado. */
    private function ensureWorkDemoOffice(): Office
    {
        $slug = trim((string) config('work_demo.office_slug', self::ACCOUNTANT_OFFICE_SLUG));

        if ($slug === '') {
            throw new LogicException('work_demo.office_slug não pode ser vazio ao carregar fixtures operacionais.');
        }

        $office = Office::query()->firstOrCreate(
            ['slug' => $slug],
            ['name' => 'Escritório Operacional Demo', 'is_active' => true],
        );

        if (! $office->is_active) {
            $office->forceFill(['is_active' => true])->save();
        }

        $this->ensureActiveSubscription($office);

        return $office;
    }

    private function seedDemoOfficeUsers(Office $office): void
    {
        if (! User::query()->where('email', 'operador@example.com')->exists()) {
            User::factory()
                ->forOffice($office, OfficeRole::Operator)
                ->create([
                    'name' => 'Operador Demo',
                    'email' => 'operador@example.com',
                    'password' => 'password',
                    'selected_office_id' => $office->id,
                ]);
        }

        if (! User::query()->where('email', 'admin@example.com')->exists()) {
            User::factory()
                ->forOffice($office, OfficeRole::Admin)
                ->withTwoFactorConfirmed()
                ->create([
                    'name' => self::ACCOUNTANT_OFFICE_NAME,
                    'email' => 'admin@example.com',
                    'password' => 'password',
                    'selected_office_id' => $office->id,
                ]);
        }

        if (! User::query()->where('email', 'viewer@example.com')->exists()) {
            User::factory()
                ->forOffice($office, OfficeRole::Viewer)
                ->create([
                    'name' => 'Viewer Demo',
                    'email' => 'viewer@example.com',
                    'password' => 'password',
                    'selected_office_id' => $office->id,
                ]);
        }

        $roles = [
            'operador@example.com' => OfficeRole::Operator,
            'admin@example.com' => OfficeRole::Admin,
            'viewer@example.com' => OfficeRole::Viewer,
        ];

        foreach ($roles as $email => $role) {
            $user = User::query()->where('email', $email)->firstOrFail();

            if (! OfficeMembership::query()
                ->where('office_id', $office->id)
                ->where('user_id', $user->id)
                ->exists()) {
                $office->users()->attach($user->id, [
                    'role' => $role->value,
                    'is_active' => true,
                ]);
            }
        }

        User::query()
            ->whereIn('email', self::LEGACY_DEMO_EMAILS)
            ->update([
                'is_active' => true,
                'selected_office_id' => $office->id,
            ]);
    }

    private function deactivateLegacyDemoUsers(): void
    {
        User::query()
            ->whereIn('email', self::LEGACY_DEMO_EMAILS)
            ->update(['is_active' => false]);
    }

    private function retireLegacySentinelOffices(): void
    {
        Office::query()
            ->whereIn('slug', self::LEGACY_SENTINEL_SLUGS)
            ->update(['is_active' => false]);
    }

    private function pointPlatformAdminDefaultOffice(Office $office): void
    {
        $user = User::query()->where('email', PlatformAdminDemoSeeder::EMAIL)->first();
        if ($user === null) {
            return;
        }

        $membership = $user->platformMemberships()
            ->where('role', 'PLATFORM_ADMIN')
            ->first();

        if ($membership !== null) {
            $membership->forceFill([
                'default_office_id' => $office->id,
                'is_active' => true,
            ])->save();
        }

        $user->forceFill([
            'is_active' => true,
            'selected_office_id' => $office->id,
        ])->save();
    }

    /**
     * Membership tenant no office plataforma para o PLATFORM_ADMIN de exemplo
     * (aba Admin + /conta/escritorio do office demo).
     */
    private function ensurePlatformAdminTenantMembership(Office $plataforma): void
    {
        $user = User::query()->where('email', PlatformAdminDemoSeeder::EMAIL)->first();
        if ($user === null) {
            return;
        }

        OfficeMembership::query()->updateOrCreate(
            [
                'office_id' => $plataforma->id,
                'user_id' => $user->id,
            ],
            [
                'role' => OfficeRole::Admin,
                'tenant_role' => TenantRole::TenantAdmin->value,
                'permission_profile_id' => null,
                'is_active' => true,
                'authorization_version' => 1,
            ],
        );
    }

    private function ensureActiveSubscription(Office $office): void
    {
        if (OfficeSubscription::query()->where('office_id', $office->id)->exists()) {
            return;
        }

        $plan = SubscriptionPlan::Professional;
        $limits = $plan->defaultLimits();
        $commercial = $plan->commercialEntitlements();
        $now = now();

        OfficeSubscription::query()->create([
            'office_id' => $office->id,
            'plan' => $plan,
            'status' => SubscriptionStatus::Active,
            'starts_at' => $now,
            'current_period_starts_at' => $now->copy()->startOfMonth(),
            'current_period_ends_at' => $now->copy()->endOfMonth(),
            'monthly_api_quota' => $limits['monthly_api_quota'],
            'commercial_monitor_units' => $commercial['commercial_monitor_units'],
            'max_clients' => $limits['max_clients'],
            'negotiated_client_limit' => null,
            'max_users' => $limits['max_users'],
            'limits' => array_merge($limits, $commercial),
            'notes' => 'Assinatura ACTIVE criada no DatabaseSeeder (dev/testing).',
        ]);
    }
}

<?php

namespace Database\Seeders;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Office;
use App\Models\OfficeSubscription;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use LogicException;

/**
 * Seed de piloto prático — massa real alinhada a `.local/dados/` (não versionado).
 *
 * Separado do {@see DatabaseSeeder} (exemplos de desenvolvimento).
 *
 * Uso:
 *   php artisan migrate:fresh --seeder=PilotSeeder
 *   php artisan db:seed --class=PilotSeeder
 *
 * Logins (senha `password`):
 * - felipe@example.com  → PLATFORM_ADMIN + office plataforma (Admin/SERPRO)
 * - gustavo@example.com → office contador (AUTO CENTER)
 *
 * PFX/PDFs ficam só em `.local/dados/`; este seeder não importa cofre.
 *
 * Produção só é permitida com `PILOT_SEED_ALLOW_PRODUCTION=true`.
 */
class PilotSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $productionAllowed = filter_var(env('PILOT_SEED_ALLOW_PRODUCTION', false), FILTER_VALIDATE_BOOL);

        if (! app()->environment(['local', 'testing']) && ! $productionAllowed) {
            throw new LogicException('PilotSeeder só pode rodar em local/testing.');
        }

        // Offices base (nomes reais aplicados pelo LocalSerproSmokeSeeder).
        $plataforma = Office::query()->updateOrCreate(
            ['slug' => PlatformAdminDemoSeeder::OFFICE_SLUG],
            ['name' => LocalSerproSmokeSeeder::PLATAFORMA_LEGAL_NAME, 'is_active' => true],
        );

        $contador = Office::query()->updateOrCreate(
            ['slug' => LocalSerproSmokeSeeder::CONTADOR_OFFICE_SLUG],
            ['name' => LocalSerproSmokeSeeder::CONTADOR_OFFICE_NAME, 'is_active' => true],
        );

        $this->ensureActiveSubscription($plataforma);
        $this->ensureActiveSubscription($contador);

        // Fixture técnica PLATFORM_ADMIN (pode ser transferida ao Felipe).
        // Se o titular já for Felipe (re-seed), o PlatformAdminDemoSeeder é no-op.
        $this->call(PlatformAdminDemoSeeder::class);

        // Massa real de .local/dados/ + usuários Felipe/Gustavo.
        $this->call(LocalSerproSmokeSeeder::class);
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
            'notes' => 'Assinatura ACTIVE criada no PilotSeeder (piloto local).',
        ]);
    }
}

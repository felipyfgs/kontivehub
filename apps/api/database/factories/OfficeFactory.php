<?php

namespace Database\Factories;

use App\Enums\OfficeLifecycleStatus;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Office;
use App\Models\OfficeSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Office>
 */
class OfficeFactory extends Factory
{
    protected $model = Office::class;

    /** Quando false, não cria assinatura ACTIVE no afterCreating (evita colisão com OfficeSubscriptionFactory). */
    public static bool $autoSubscription = true;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)),
            'is_active' => true,
            'lifecycle_status' => OfficeLifecycleStatus::Active,
            'timezone' => 'America/Sao_Paulo',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Office $office): void {
            if (! static::$autoSubscription) {
                return;
            }

            if (OfficeSubscription::query()->where('office_id', $office->id)->exists()) {
                return;
            }

            $plan = SubscriptionPlan::Professional;
            $limits = $plan->defaultLimits();
            $commercial = $plan->commercialEntitlements();
            $now = now();

            // create() direto (não factory) para não reentrar no ciclo office→subscription.
            OfficeSubscription::query()->create([
                'office_id' => $office->id,
                'plan' => $plan,
                'status' => SubscriptionStatus::Active,
                'trial_ends_at' => null,
                'starts_at' => $now,
                'ends_at' => null,
                'current_period_starts_at' => $now,
                'current_period_ends_at' => $now->copy()->addMonthNoOverflow()->subSecond(),
                'monthly_api_quota' => $limits['monthly_api_quota'],
                'commercial_monitor_units' => $commercial['commercial_monitor_units'],
                'max_clients' => $plan->commercialMaxClients(),
                'negotiated_client_limit' => null,
                'max_users' => $limits['max_users'],
                'limits' => array_merge($limits, $commercial, [
                    'max_clients' => $plan->commercialMaxClients(),
                ]),
                'notes' => null,
            ]);
        });
    }

    public function withoutSubscription(): static
    {
        return $this->afterMaking(function (): void {
            static::$autoSubscription = false;
        })->afterCreating(function (): void {
            static::$autoSubscription = true;
        });
    }
}

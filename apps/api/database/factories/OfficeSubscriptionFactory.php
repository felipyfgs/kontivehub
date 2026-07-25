<?php

namespace Database\Factories;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Models\Office;
use App\Models\OfficeSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficeSubscription>
 */
class OfficeSubscriptionFactory extends Factory
{
    protected $model = OfficeSubscription::class;

    public function definition(): array
    {
        $plan = SubscriptionPlan::Professional;
        $limits = $plan->defaultLimits();
        $commercial = $plan->commercialEntitlements();
        $now = now();

        return [
            'office_id' => function () {
                $prev = OfficeFactory::$autoSubscription;
                OfficeFactory::$autoSubscription = false;
                try {
                    return Office::factory()->create()->id;
                } finally {
                    OfficeFactory::$autoSubscription = $prev;
                }
            },
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
        ];
    }

    public function forOffice(Office $office): static
    {
        return $this->state(fn () => ['office_id' => $office->id]);
    }

    public function plan(SubscriptionPlan $plan): static
    {
        $limits = $plan->defaultLimits();
        $commercial = $plan->commercialEntitlements();

        return $this->state(fn () => [
            'plan' => $plan,
            'monthly_api_quota' => $limits['monthly_api_quota'],
            'commercial_monitor_units' => $commercial['commercial_monitor_units'],
            'max_clients' => $plan->commercialMaxClients(),
            'max_users' => $limits['max_users'],
            'limits' => array_merge($limits, $commercial, [
                'max_clients' => $plan->commercialMaxClients(),
            ]),
        ]);
    }

    public function trial(): static
    {
        $plan = SubscriptionPlan::Starter;
        $limits = $plan->defaultLimits();
        $commercial = $plan->commercialEntitlements();

        return $this->state(fn () => [
            'status' => SubscriptionStatus::Trial,
            'trial_ends_at' => now()->addDays(14),
            'plan' => $plan,
            'monthly_api_quota' => $limits['monthly_api_quota'],
            'commercial_monitor_units' => $commercial['commercial_monitor_units'],
            'max_clients' => $plan->commercialMaxClients(),
            'max_users' => $limits['max_users'],
            'limits' => array_merge($limits, $commercial, [
                'max_clients' => $plan->commercialMaxClients(),
            ]),
        ]);
    }

    public function withNegotiatedClientLimit(int $limit): static
    {
        return $this->state(fn () => ['negotiated_client_limit' => $limit]);
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::Active]);
    }

    public function pastDue(): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::PastDue]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::Suspended]);
    }

    public function canceled(): static
    {
        return $this->state(fn () => [
            'status' => SubscriptionStatus::Canceled,
            'ends_at' => now(),
        ]);
    }
}

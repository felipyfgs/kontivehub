<?php

namespace Database\Factories;

use App\Enums\SerproEnvironment;
use App\Enums\TenantSerproOnboardingStatus;
use App\Models\Tenant;
use App\Models\TenantSerproOnboardingState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantSerproOnboardingState>
 */
class TenantSerproOnboardingStateFactory extends Factory
{
    protected $model = TenantSerproOnboardingState::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'environment' => SerproEnvironment::Production,
            'status' => TenantSerproOnboardingStatus::Incomplete,
            'idempotency_key' => null,
            'last_step' => null,
            'actionable_code' => null,
            'actionable_message' => null,
            'technical_code' => null,
            'technical_message' => null,
            'correlation_id' => null,
            'ready_at' => null,
            'provisioning_started_at' => null,
            'authorized_at' => null,
            'last_transition_at' => now(),
            'metadata' => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => TenantSerproOnboardingStatus::Ready,
            'ready_at' => now(),
            'last_transition_at' => now(),
        ]);
    }

    public function authorized(): static
    {
        return $this->state(fn () => [
            'status' => TenantSerproOnboardingStatus::Authorized,
            'ready_at' => now()->subHour(),
            'provisioning_started_at' => now()->subMinutes(30),
            'authorized_at' => now(),
            'last_transition_at' => now(),
        ]);
    }

    public function actionRequired(string $code = 'PROFILE_INCOMPLETE'): static
    {
        return $this->state(fn () => [
            'status' => TenantSerproOnboardingStatus::ActionRequired,
            'actionable_code' => $code,
            'actionable_message' => 'Pendência acionável no escritório.',
            'last_transition_at' => now(),
        ]);
    }
}

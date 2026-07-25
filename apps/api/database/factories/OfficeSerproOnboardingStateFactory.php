<?php

namespace Database\Factories;

use App\Enums\OfficeSerproOnboardingStatus;
use App\Enums\SerproEnvironment;
use App\Models\Office;
use App\Models\OfficeSerproOnboardingState;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficeSerproOnboardingState>
 */
class OfficeSerproOnboardingStateFactory extends Factory
{
    protected $model = OfficeSerproOnboardingState::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'environment' => SerproEnvironment::Production,
            'status' => OfficeSerproOnboardingStatus::Incomplete,
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

    public function forOffice(Office $office): static
    {
        return $this->state(fn () => ['office_id' => $office->id]);
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => OfficeSerproOnboardingStatus::Ready,
            'ready_at' => now(),
            'last_transition_at' => now(),
        ]);
    }

    public function authorized(): static
    {
        return $this->state(fn () => [
            'status' => OfficeSerproOnboardingStatus::Authorized,
            'ready_at' => now()->subHour(),
            'provisioning_started_at' => now()->subMinutes(30),
            'authorized_at' => now(),
            'last_transition_at' => now(),
        ]);
    }

    public function actionRequired(string $code = 'PROFILE_INCOMPLETE'): static
    {
        return $this->state(fn () => [
            'status' => OfficeSerproOnboardingStatus::ActionRequired,
            'actionable_code' => $code,
            'actionable_message' => 'Pendência acionável no escritório.',
            'last_transition_at' => now(),
        ]);
    }
}

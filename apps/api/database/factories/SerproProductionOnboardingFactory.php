<?php

namespace Database\Factories;

use App\Enums\SerproEnvironment;
use App\Enums\SerproProductionOnboardingStatus;
use App\Enums\SerproProductionOnboardingStep;
use App\Models\Office;
use App\Models\SerproProductionOnboarding;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SerproProductionOnboarding>
 */
class SerproProductionOnboardingFactory extends Factory
{
    protected $model = SerproProductionOnboarding::class;

    public function definition(): array
    {
        $text = (string) config('serpro.production_onboarding.consent_text', 'consentimento-serpro-producao');

        return [
            'office_id' => Office::factory(),
            'actor_user_id' => User::factory(),
            'environment' => SerproEnvironment::Production,
            'idempotency_key' => Str::uuid()->toString(),
            'status' => SerproProductionOnboardingStatus::Pending,
            'current_step' => SerproProductionOnboardingStep::ValidateInput,
            'completed_steps' => [],
            'consent_version' => (string) config('serpro.production_onboarding.consent_version', 'serpro-prod-onboarding.v1'),
            'consent_text_sha256' => hash('sha256', $text),
            'consented_at' => now(),
            'correlation_id' => Str::uuid()->toString(),
            'metadata' => null,
        ];
    }
}

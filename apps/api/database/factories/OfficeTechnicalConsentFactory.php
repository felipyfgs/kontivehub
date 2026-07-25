<?php

namespace Database\Factories;

use App\Enums\OfficeCredentialPurpose;
use App\Models\Office;
use App\Models\OfficeTechnicalConsent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficeTechnicalConsent>
 */
class OfficeTechnicalConsentFactory extends Factory
{
    protected $model = OfficeTechnicalConsent::class;

    public function definition(): array
    {
        $purposes = [
            OfficeCredentialPurpose::CanonicalECnpjA1->value,
            OfficeCredentialPurpose::SerproTermSigning->value,
            OfficeCredentialPurpose::NfeAutXmlDistDfe->value,
        ];

        return [
            'office_id' => Office::factory(),
            'version_code' => OfficeTechnicalConsent::VERSION_UNIFIED_A1_V1,
            'purposes_presented' => $purposes,
            'actor_user_id' => User::factory(),
            'consented_at' => now(),
            'revoked_at' => null,
            'payload_sha256' => hash('sha256', implode('|', $purposes)),
            'metadata' => null,
        ];
    }

    public function forOffice(Office $office): static
    {
        return $this->state(fn () => ['office_id' => $office->id]);
    }

    public function byUser(User $user): static
    {
        return $this->state(fn () => ['actor_user_id' => $user->id]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'revoked_at' => now(),
        ]);
    }
}

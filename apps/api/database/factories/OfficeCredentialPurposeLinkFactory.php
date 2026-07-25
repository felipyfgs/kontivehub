<?php

namespace Database\Factories;

use App\Enums\CredentialStatus;
use App\Enums\OfficeCredentialPurpose;
use App\Models\Office;
use App\Models\OfficeCredential;
use App\Models\OfficeCredentialPurposeLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficeCredentialPurposeLink>
 */
class OfficeCredentialPurposeLinkFactory extends Factory
{
    protected $model = OfficeCredentialPurposeLink::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'office_credential_id' => OfficeCredential::factory()->canonical(),
            'purpose' => OfficeCredentialPurpose::SerproTermSigning,
            'status' => CredentialStatus::Active,
            'linked_at' => now(),
            'revoked_at' => null,
            'linked_by_user_id' => null,
            'metadata' => null,
        ];
    }

    public function forOffice(Office $office): static
    {
        return $this->state(fn () => ['office_id' => $office->id]);
    }

    public function forCredential(OfficeCredential $credential): static
    {
        return $this->state(fn () => [
            'office_id' => $credential->office_id,
            'office_credential_id' => $credential->id,
        ]);
    }

    public function serproTermSigning(): static
    {
        return $this->state(fn () => [
            'purpose' => OfficeCredentialPurpose::SerproTermSigning,
        ]);
    }

    public function nfeAutXml(): static
    {
        return $this->state(fn () => [
            'purpose' => OfficeCredentialPurpose::NfeAutXmlDistDfe,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => CredentialStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }
}

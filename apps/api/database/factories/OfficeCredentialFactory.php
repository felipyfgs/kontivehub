<?php

namespace Database\Factories;

use App\Enums\CredentialStatus;
use App\Enums\OfficeCredentialPurpose;
use App\Models\Office;
use App\Models\OfficeCredential;
use App\Models\OfficeFiscalIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OfficeCredential>
 */
class OfficeCredentialFactory extends Factory
{
    protected $model = OfficeCredential::class;

    public function definition(): array
    {
        $cnpj = '11222333000181';

        return [
            'office_id' => Office::factory(),
            'office_fiscal_identity_id' => OfficeFiscalIdentity::factory(),
            'purpose' => OfficeCredentialPurpose::NfeAutXmlDistDfe,
            'status' => CredentialStatus::Active,
            'subject_name' => 'ESCRITORIO TESTE:CNPJ',
            'holder_cnpj' => $cnpj,
            'fingerprint_sha256' => hash('sha256', Str::uuid()->toString()),
            'valid_from' => now()->subYear(),
            'valid_to' => now()->addYear(),
            'vault_object_id' => strtoupper(Str::ulid()->toBase32()),
            'activated_at' => now(),
        ];
    }

    public function forIdentity(OfficeFiscalIdentity $identity): static
    {
        return $this->state(fn () => [
            'office_id' => $identity->office_id,
            'office_fiscal_identity_id' => $identity->id,
            'holder_cnpj' => $identity->cnpj,
        ]);
    }

    public function forOffice(Office $office): static
    {
        return $this->state(fn () => ['office_id' => $office->id]);
    }

    public function superseded(): static
    {
        return $this->state(fn () => [
            'status' => CredentialStatus::Superseded,
            'superseded_at' => now(),
        ]);
    }

    /**
     * Credencial física canônica e-CNPJ A1 (office-scoped; identity opcional).
     */
    public function canonical(): static
    {
        return $this->state(fn () => [
            'purpose' => OfficeCredentialPurpose::CanonicalECnpjA1,
            'office_fiscal_identity_id' => null,
        ]);
    }
}

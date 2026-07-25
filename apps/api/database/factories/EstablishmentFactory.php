<?php

namespace Database\Factories;

use App\Domain\Cnpj;
use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Establishment>
 */
class EstablishmentFactory extends Factory
{
    protected $model = Establishment::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'client_id' => Client::factory(),
            'cnpj' => '11222333000181',
            'trade_name' => fake()->company(),
            'is_matrix' => true,
            'is_active' => true,
            'registration_status' => RegistrationStatus::Unknown,
            'registration_status_at' => null,
            'registration_status_reason' => null,
            'activity_started_at' => null,
            'main_cnae_code' => null,
            'main_cnae_name' => null,
            'address_postal_code' => null,
            'address_street_type' => null,
            'address_street' => null,
            'address_number' => null,
            'address_complement' => null,
            'address_district' => null,
            'address_city' => null,
            'address_city_ibge_code' => null,
            'address_state' => null,
            'address_country' => 'BR',
            'public_email' => null,
            'public_phone' => null,
            'capture_enabled' => true,
            'registration_source' => RegistrationSource::Manual,
            'registration_refreshed_at' => null,
        ];
    }

    public function forClient(Client $client, ?string $cnpj = null): static
    {
        $cnpj ??= self::cnpjWithRoot($client->root_cnpj);

        return $this->state(fn () => [
            'office_id' => $client->office_id,
            'client_id' => $client->id,
            'cnpj' => $cnpj,
        ]);
    }

    public function matrix(): static
    {
        return $this->state(fn () => ['is_matrix' => true]);
    }

    public function branch(): static
    {
        return $this->state(fn () => ['is_matrix' => false]);
    }

    public function captureDisabled(): static
    {
        return $this->state(fn () => ['capture_enabled' => false]);
    }

    public static function cnpjWithRoot(string $root8, string $branch = '0001'): string
    {
        $root8 = strtoupper(substr($root8, 0, 8));
        $branch = str_pad(strtoupper(substr($branch, 0, 4)), 4, '0', STR_PAD_LEFT);
        $base = $root8.$branch;
        $d1 = self::digit($base, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        $d2 = self::digit($base.$d1, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        $full = $base.$d1.$d2;

        return Cnpj::parse($full)->value();
    }

    /**
     * @param  list<int>  $weights
     */
    private static function digit(string $base, array $weights): string
    {
        $sum = 0;
        for ($i = 0, $len = strlen($base); $i < $len; $i++) {
            $sum += (ord($base[$i]) - 48) * $weights[$i];
        }
        $mod = $sum % 11;

        return (string) ($mod < 2 ? 0 : 11 - $mod);
    }
}

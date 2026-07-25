<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Office;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientContact>
 */
class ClientContactFactory extends Factory
{
    protected $model = ClientContact::class;

    public function definition(): array
    {
        return [
            'office_id' => Office::factory(),
            'client_id' => Client::factory(),
            'name' => fake()->name(),
            'role' => fake()->optional()->jobTitle(),
            'email' => fake()->optional()->safeEmail(),
            'phone' => fake()->optional()->numerify('119########'),
            'is_whatsapp' => false,
            'is_primary' => false,
            'receives_alerts' => false,
            'notes' => null,
            'is_active' => true,
        ];
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => [
            'office_id' => $client->office_id,
            'client_id' => $client->id,
        ]);
    }

    public function primary(): static
    {
        return $this->state(fn () => ['is_primary' => true]);
    }
}

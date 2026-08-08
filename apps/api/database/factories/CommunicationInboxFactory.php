<?php

namespace Database\Factories;

use App\Enums\Communication\InboxStatus;
use App\Models\CommunicationInbox;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CommunicationInbox> */
class CommunicationInboxFactory extends Factory
{
    protected $model = CommunicationInbox::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'WhatsApp '.fake()->unique()->numerify('####'),
            'session_id' => 'session-'.fake()->unique()->uuid(),
            'status' => InboxStatus::Disconnected,
            'is_enabled' => false,
            'is_default' => false,
            'lock_version' => 1,
        ];
    }
}

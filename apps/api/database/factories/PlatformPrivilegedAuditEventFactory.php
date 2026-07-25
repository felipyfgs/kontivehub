<?php

namespace Database\Factories;

use App\Models\Office;
use App\Models\PlatformPrivilegedAuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PlatformPrivilegedAuditEvent>
 */
class PlatformPrivilegedAuditEventFactory extends Factory
{
    protected $model = PlatformPrivilegedAuditEvent::class;

    public function definition(): array
    {
        return [
            'actor_user_id' => User::factory(),
            'office_id' => Office::factory(),
            'action' => PlatformPrivilegedAuditEvent::ACTION_SELECT_OFFICE,
            'target_type' => Office::class,
            'target_id' => null,
            'result' => PlatformPrivilegedAuditEvent::RESULT_SUCCESS,
            'request_id' => (string) Str::uuid(),
            'metadata' => [
                'access_mode' => 'platform_privileged',
                'source' => 'factory',
            ],
            'created_at' => now(),
        ];
    }

    public function forActor(User $user): static
    {
        return $this->state(fn () => ['actor_user_id' => $user->id]);
    }

    public function forOffice(Office $office): static
    {
        return $this->state(fn () => [
            'office_id' => $office->id,
            'target_type' => Office::class,
            'target_id' => $office->id,
        ]);
    }

    public function denied(): static
    {
        return $this->state(fn () => [
            'result' => PlatformPrivilegedAuditEvent::RESULT_DENIED,
        ]);
    }

    public function withSensitiveMetadata(): static
    {
        return $this->state(fn () => [
            'metadata' => [
                'password' => 'super-secret',
                'pfx' => 'binary-blob',
                'access_mode' => 'platform_privileged',
                'office_slug' => 'acme',
            ],
        ]);
    }
}

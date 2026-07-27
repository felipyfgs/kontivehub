<?php

namespace Database\Factories;

use App\Models\PlatformPrivilegedAuditEvent;
use App\Models\Tenant;
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
            'tenant_id' => Tenant::factory(),
            'action' => PlatformPrivilegedAuditEvent::ACTION_SELECT_TENANT,
            'target_type' => Tenant::class,
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

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => [
            'tenant_id' => $tenant->id,
            'target_type' => Tenant::class,
            'target_id' => $tenant->id,
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
                'tenant_slug' => 'acme',
            ],
        ]);
    }
}

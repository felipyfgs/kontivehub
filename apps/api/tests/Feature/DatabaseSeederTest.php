<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Establishment;
use App\Models\PlatformMembership;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\Development\DevelopmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_testing_baseline_does_not_require_local_dataset(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseHas('tenants', ['slug' => 'testing']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('tenant_memberships', 1);
        $this->assertDatabaseCount('clients', 0);
    }

    public function test_development_data_is_idempotent(): void
    {
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'local';

        try {
            $this->seed(DevelopmentSeeder::class);
            $this->seed(DevelopmentSeeder::class);
        } finally {
            $this->app['env'] = $originalEnvironment;
        }

        $this->assertSame(2, Tenant::query()->count());
        $this->assertSame(2, User::query()->count());
        $this->assertSame(1, PlatformMembership::query()->count());
        $this->assertSame(1, TenantMembership::query()->count());
        $this->assertSame(1, Client::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, Establishment::query()->withoutGlobalScopes()->count());
        $this->assertDatabaseCount('client_contacts', 1);
    }
}

<?php

namespace Database\Seeders\Testing;

use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Enums\TenantLifecycleStatus;
use App\Enums\TenantRole;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

final class TestingBaselineSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('testing')) {
            throw new LogicException('TestingBaselineSeeder permitido somente em testing.');
        }

        DB::transaction(function (): void {
            $tenant = Tenant::query()->updateOrCreate(
                ['slug' => 'testing'],
                [
                    'name' => 'Tenant de Testes',
                    'is_active' => true,
                    'lifecycle_status' => TenantLifecycleStatus::Active,
                    'timezone' => 'America/Sao_Paulo',
                    'communication_enabled' => false,
                ],
            );

            $plan = SubscriptionPlan::Professional;
            $limits = $plan->defaultLimits();
            $commercial = $plan->commercialEntitlements();
            TenantSubscription::query()->updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'plan' => $plan,
                    'status' => SubscriptionStatus::Active,
                    'starts_at' => '2026-01-01 00:00:00',
                    'current_period_starts_at' => '2026-01-01 00:00:00',
                    'current_period_ends_at' => '2099-12-31 23:59:59',
                    'monthly_api_quota' => $limits['monthly_api_quota'],
                    'commercial_monitor_units' => $commercial['commercial_monitor_units'],
                    'max_clients' => $limits['max_clients'],
                    'max_users' => $limits['max_users'],
                    'limits' => [...$limits, ...$commercial],
                ],
            );

            $user = User::query()->firstOrNew(['email' => 'admin@example.test']);
            $user->forceFill([
                'name' => 'Administrador de Testes',
                'password' => 'password',
                'email_verified_at' => '2026-01-01 00:00:00',
                'is_active' => true,
                'password_change_required' => false,
                'selected_tenant_id' => $tenant->id,
            ])->save();

            TenantMembership::query()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                [
                    'role' => TenantRole::TenantAdmin,
                    'permission_profile_id' => null,
                    'authorization_version' => 1,
                    'is_active' => true,
                ],
            );
        });
    }
}

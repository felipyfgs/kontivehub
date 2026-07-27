<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantMonitorSchedulePolicy;
use App\Services\FiscalMonitoring\MonitorScheduleDayHasher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantMonitorSchedulePolicy>
 */
class TenantMonitorSchedulePolicyFactory extends Factory
{
    protected $model = TenantMonitorSchedulePolicy::class;

    public function definition(): array
    {
        $tenantId = null;

        return [
            'tenant_id' => function () use (&$tenantId) {
                $tenantId = Tenant::factory()->create()->id;

                return $tenantId;
            },
            'monitor_key' => 'sitfis',
            'day_of_month' => function (array $attrs) {
                return MonitorScheduleDayHasher::defaultDay((int) $attrs['tenant_id'], (string) $attrs['monitor_key']);
            },
            'is_custom' => false,
            'timezone' => 'America/Sao_Paulo',
            'updated_by_user_id' => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }

    public function customDay(int $day): static
    {
        return $this->state(fn () => [
            'day_of_month' => $day,
            'is_custom' => true,
        ]);
    }
}

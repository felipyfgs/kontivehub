<?php

namespace Database\Factories;

use App\Models\Office;
use App\Models\OfficeMonitorSchedulePolicy;
use App\Services\FiscalMonitoring\MonitorScheduleDayHasher;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficeMonitorSchedulePolicy>
 */
class OfficeMonitorSchedulePolicyFactory extends Factory
{
    protected $model = OfficeMonitorSchedulePolicy::class;

    public function definition(): array
    {
        $officeId = null;

        return [
            'office_id' => function () use (&$officeId) {
                $officeId = Office::factory()->create()->id;

                return $officeId;
            },
            'monitor_key' => 'sitfis',
            'day_of_month' => function (array $attrs) {
                return MonitorScheduleDayHasher::defaultDay((int) $attrs['office_id'], (string) $attrs['monitor_key']);
            },
            'is_custom' => false,
            'timezone' => 'America/Sao_Paulo',
            'updated_by_user_id' => null,
        ];
    }

    public function forOffice(Office $office): static
    {
        return $this->state(fn () => ['office_id' => $office->id]);
    }

    public function customDay(int $day): static
    {
        return $this->state(fn () => [
            'day_of_month' => $day,
            'is_custom' => true,
        ]);
    }
}

<?php

namespace Database\Factories;

use App\Enums\MonitorCommercialDispatchState;
use App\Enums\MonitorCommercialOrigin;
use App\Models\Client;
use App\Models\MonitorCommercialLedgerEntry;
use App\Models\Office;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<MonitorCommercialLedgerEntry>
 */
class MonitorCommercialLedgerEntryFactory extends Factory
{
    protected $model = MonitorCommercialLedgerEntry::class;

    public function definition(): array
    {
        $start = now()->startOfDay();
        $end = $start->copy()->addMonthNoOverflow()->subSecond();

        return [
            'office_id' => Office::factory(),
            'client_id' => function (array $attrs) {
                return Client::factory()->forOffice(
                    Office::query()->findOrFail($attrs['office_id'])
                )->create()->id;
            },
            'monitor_key' => 'sitfis',
            'origin' => MonitorCommercialOrigin::Manual,
            'dispatch_state' => MonitorCommercialDispatchState::Pending,
            'quota_units' => 0,
            'period_starts_at' => $start,
            'period_ends_at' => $end,
            'period_key' => $start->toDateString(),
            'idempotency_key' => 'mcle-'.Str::uuid()->toString(),
            'technical_correlation_id' => null,
            'technical_usage_entry_id' => null,
            'dispatched_at' => null,
            'completed_at' => null,
            'blocked_reason' => null,
            'metadata' => null,
        ];
    }

    public function forOffice(Office $office): static
    {
        return $this->state(fn () => ['office_id' => $office->id]);
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => [
            'office_id' => $client->office_id,
            'client_id' => $client->id,
        ]);
    }

    public function inaugural(): static
    {
        return $this->state(fn () => [
            'origin' => MonitorCommercialOrigin::Inaugural,
            'quota_units' => 0,
        ]);
    }

    public function scheduled(): static
    {
        return $this->state(fn () => [
            'origin' => MonitorCommercialOrigin::Scheduled,
        ]);
    }

    public function monitor(string $monitorKey): static
    {
        return $this->state(fn () => [
            'monitor_key' => strtolower(trim($monitorKey)),
        ]);
    }

    public function forPeriod(string $periodKey): static
    {
        $start = CarbonImmutable::parse($periodKey)->startOfDay();
        $end = $start->addMonthNoOverflow()->subSecond();

        return $this->state(fn () => [
            'period_key' => $start->toDateString(),
            'period_starts_at' => $start,
            'period_ends_at' => $end,
        ]);
    }

    public function manualDispatched(int $quotaUnits = 1): static
    {
        return $this->state(fn () => [
            'origin' => MonitorCommercialOrigin::Manual,
            'dispatch_state' => MonitorCommercialDispatchState::Dispatched,
            'quota_units' => $quotaUnits,
            'dispatched_at' => now(),
        ]);
    }

    public function dispatched(int $quotaUnits = 1): static
    {
        return $this->state(fn () => [
            'dispatch_state' => MonitorCommercialDispatchState::Dispatched,
            'quota_units' => $quotaUnits,
            'dispatched_at' => now(),
        ]);
    }
}

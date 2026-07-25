<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class SurfaceInventoryApiTest extends TestCase
{
    public function test_live_route_total_matches_inventory_summary(): void
    {
        $summary = $this->summary();

        Artisan::call('route:list', ['--json' => true]);
        $live = json_decode(Artisan::output(), true);
        $this->assertIsArray($live);

        $this->assertSame(
            (int) $summary['apiTotal'],
            count($live),
            'Totais do inventário e de artisan route:list --json divergem.',
        );
    }

    public function test_inventory_route_keys_match_the_live_route_set_exactly(): void
    {
        $inventory = json_decode(
            (string) file_get_contents(base_path('tests/fixtures/surface-inventory/api-routes.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($inventory);
        $this->assertNotEmpty($inventory);

        Artisan::call('route:list', ['--json' => true]);
        $live = json_decode(Artisan::output(), true);
        $this->assertIsArray($live);

        $liveKeys = collect($live)
            ->map(fn (array $row): string => $this->routeKey(
                (string) ($row['method'] ?? ''),
                (string) ($row['uri'] ?? ''),
            ))
            ->filter(fn (string $key): bool => ! str_ends_with($key, ' '))
            ->sort()
            ->values()
            ->all();

        $inventoryKeys = collect($inventory)
            ->map(fn (array $row): string => $this->routeKey(
                (string) ($row['method'] ?? ''),
                (string) ($row['uri'] ?? ''),
            ))
            ->sort()
            ->values()
            ->all();

        $this->assertSame(
            $inventoryKeys,
            $liveKeys,
            'O conjunto método + URI diverge; regenere conscientemente o inventário e o grafo.',
        );
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $path = base_path('tests/fixtures/surface-inventory/summary.json');
        $this->assertFileExists($path);

        $summary = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($summary);
        $this->assertArrayHasKey('apiTotal', $summary);

        return $summary;
    }

    private function routeKey(string $method, string $uri): string
    {
        $normalizedMethod = explode('|', $method)[0];

        return $normalizedMethod.' '.$uri;
    }
}

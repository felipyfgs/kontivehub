<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class UseCaseTestabilityGraphTest extends TestCase
{
    public function test_graph_digest_and_live_api_route_classification_are_exact(): void
    {
        $graph = $this->graph();
        $digest = (string) ($graph['digest'] ?? '');
        unset($graph['digest']);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $digest);
        $this->assertSame(
            $digest,
            hash('sha256', json_encode(
                $graph,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )),
            'O conteúdo do grafo diverge do digest versionado.',
        );

        Artisan::call('route:list', ['--json' => true]);
        $liveRoutes = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($liveRoutes);

        $liveKeys = collect($liveRoutes)
            ->map(fn (array $route): string => $this->routeKey(
                (string) ($route['method'] ?? ''),
                (string) ($route['uri'] ?? ''),
            ))
            ->sort()
            ->values()
            ->all();
        $routeNodes = collect($graph['nodes'] ?? [])->where('type', 'api-route');
        $graphKeys = $routeNodes
            ->map(fn (array $route): string => $this->routeKey(
                (string) ($route['method'] ?? ''),
                (string) ($route['uri'] ?? ''),
            ))
            ->sort()
            ->values()
            ->all();

        $this->assertSame($liveKeys, $graphKeys);
        $this->assertSame(count($liveKeys), (int) data_get($graph, 'summary.apiRoutes'));
        $this->assertTrue($routeNodes->every(
            fn (array $route): bool => ($route['journeyId'] ?? '') !== '' && ($route['action'] ?? '') !== '',
        ));

        $handledRouteIds = collect($graph['edges'] ?? [])
            ->where('type', 'handled-by')
            ->pluck('from')
            ->unique();
        $this->assertEqualsCanonicalizing($routeNodes->pluck('id')->all(), $handledRouteIds->all());
    }

    public function test_critical_journeys_have_valid_l1_to_l3_evidence(): void
    {
        $graph = $this->graph();
        $criticalIds = collect($graph['nodes'] ?? [])
            ->where('type', 'journey')
            ->where('critical', true)
            ->pluck('journeyId');

        $this->assertSame(4, $criticalIds->count());
        foreach ($criticalIds as $journeyId) {
            $evidence = collect($graph['evidence'] ?? [])->where('journeyId', $journeyId);
            $this->assertEqualsCanonicalizing(
                ['L1', 'L2', 'L3'],
                $evidence->pluck('level')->unique()->all(),
                "Jornada crítica {$journeyId} sem evidência L1–L3.",
            );
        }

        collect($graph['evidence'] ?? [])
            ->filter(fn (array $item): bool => str_starts_with((string) $item['file'], 'apps/api/'))
            ->each(function (array $item): void {
                $relative = substr((string) $item['file'], strlen('apps/api/'));
                $path = base_path($relative);
                $this->assertFileExists($path);
                $this->assertStringContainsString(
                    (string) $item['anchor'],
                    (string) file_get_contents($path),
                    "Âncora de evidência ausente em {$item['file']}",
                );
            });
    }

    /** @return array<string, mixed> */
    private function graph(): array
    {
        $path = base_path('tests/fixtures/use-case-testability/graph.json');
        $this->assertFileExists($path);
        $graph = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($graph);

        return $graph;
    }

    private function routeKey(string $method, string $uri): string
    {
        return explode('|', $method)[0].' '.$uri;
    }
}

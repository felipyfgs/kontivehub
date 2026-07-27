<?php

namespace Tests\Unit\Contracts;

use App\Enums\PlatformRole;
use App\Enums\TenantRole;
use Illuminate\Routing\Route;
use Tests\TestCase;

class PublicOpenApiContractTest extends TestCase
{
    public function test_contract_is_canonical_and_matches_every_public_route(): void
    {
        $document = $this->document();

        $this->assertSame('3.1.0', $document['openapi']);
        $this->assertSame('1.0.0', $document['info']['version']);
        $this->assertSame(
            array_column(TenantRole::cases(), 'value'),
            $document['components']['schemas']['TenantRole']['enum'],
        );
        $this->assertSame(
            array_column(PlatformRole::cases(), 'value'),
            $document['components']['schemas']['PlatformRole']['enum'],
        );

        /** @var Route $route */
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if ($uri !== 'api/v1' && ! str_starts_with($uri, 'api/v1/')) {
                continue;
            }

            $path = '/'.preg_replace('/\{([^}]+)\?\}/', '{$1}', $uri);
            foreach ($route->methods() as $method) {
                $method = strtolower($method);
                if (in_array($method, ['head', 'options'], true)) {
                    continue;
                }

                $this->assertArrayHasKey($path, $document['paths']);
                $this->assertArrayHasKey($method, $document['paths'][$path], "{$method} {$path}");
            }
        }

        $json = json_encode($document, JSON_THROW_ON_ERROR);
        $this->assertDoesNotMatchRegularExpression(
            '/\b(?:office_id|offices|legacy|deprecated|a1)\b/i',
            $json,
        );
    }

    public function test_saved_filter_and_identity_schemas_are_closed(): void
    {
        $schemas = $this->document()['components']['schemas'];

        foreach ([
            'Tenant',
            'TenantMembership',
            'PermissionProfileSummary',
            'MeUser',
            'MeResponse',
            'DataTableFilterModel',
            'SavedListFilter',
            'CreateSavedListFilterBody',
            'UpdateSavedListFilterBody',
        ] as $schema) {
            $this->assertFalse($schemas[$schema]['additionalProperties'], $schema);
        }

        $this->assertArrayHasKey('kind', $schemas['DocsSavedFilterPayload']['properties']);
        $this->assertSame(1, $schemas['SavedListFilter']['properties']['schema_version']['const']);
    }

    /**
     * @return array<string, mixed>
     */
    private function document(): array
    {
        $contents = file_get_contents(resource_path('contracts/public.openapi.json'));
        $this->assertIsString($contents);

        return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    }
}

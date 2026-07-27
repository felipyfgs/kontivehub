<?php

declare(strict_types=1);

use App\Models\SavedListFilter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;

$root = dirname(__DIR__, 2);

require $root.'/vendor/autoload.php';

$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$paths = [];
$tags = [];

/** @var Route $route */
foreach ($app['router']->getRoutes()->getRoutes() as $route) {
    $uri = $route->uri();
    if ($uri !== 'api/v1' && ! str_starts_with($uri, 'api/v1/')) {
        continue;
    }

    $path = '/'.preg_replace('/\{([^}]+)\?\}/', '{$1}', $uri);
    $segments = explode('/', trim($path, '/'));
    $tag = $segments[2] ?? 'root';
    $tags[$tag] = true;

    foreach ($route->methods() as $method) {
        $method = strtolower($method);
        if (in_array($method, ['head', 'options'], true)) {
            continue;
        }

        $operation = [
            'operationId' => operationId($method, $path),
            'tags' => [$tag],
            'summary' => actionSummary($route),
            'responses' => [
                'default' => [
                    'description' => 'Resposta definida pela operação Laravel.',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/JsonResponse'],
                        ],
                    ],
                ],
            ],
        ];

        preg_match_all('/\{([^}]+)\}/', $path, $matches);
        if (($matches[1] ?? []) !== []) {
            $operation['parameters'] = array_map(
                static fn (string $name): array => [
                    'name' => $name,
                    'in' => 'path',
                    'required' => true,
                    'schema' => ['type' => 'string', 'minLength' => 1],
                ],
                $matches[1],
            );
        }

        $middleware = implode('|', $route->gatherMiddleware());
        if (str_contains($middleware, 'auth:sanctum')) {
            $operation['security'] = [['sanctumCookie' => []]];
        }

        applyKnownContract($operation, $method, $path);
        $paths[$path][$method] = $operation;
    }
}

ksort($paths);
foreach ($paths as &$operations) {
    ksort($operations);
}
unset($operations);

$tagDefinitions = array_map(
    static fn (string $name): array => ['name' => $name],
    array_keys($tags),
);
usort($tagDefinitions, static fn (array $left, array $right): int => $left['name'] <=> $right['name']);

$document = [
    'openapi' => '3.1.0',
    'jsonSchemaDialect' => 'https://json-schema.org/draft/2020-12/schema',
    'info' => [
        'title' => 'KontiveHub Public API',
        'version' => '1.0.0',
        'description' => 'Contrato público canônico da API /api/v1 consumido pela SPA Nuxt.',
    ],
    'servers' => [['url' => '/']],
    'tags' => $tagDefinitions,
    'paths' => $paths,
    'components' => [
        'securitySchemes' => [
            'sanctumCookie' => [
                'type' => 'apiKey',
                'in' => 'cookie',
                'name' => 'laravel_session',
                'description' => 'Sessão first-party protegida por Sanctum e CSRF.',
            ],
        ],
        'schemas' => schemas(),
    ],
];

$json = json_encode(
    $document,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
)."\n";
$target = $root.'/resources/contracts/public.openapi.json';

if (in_array('--check', $argv, true)) {
    $current = is_file($target) ? file_get_contents($target) : false;
    if ($current !== $json) {
        fwrite(STDERR, "public.openapi.json está desatualizado; execute o gerador.\n");
        exit(1);
    }

    fwrite(STDOUT, "public.openapi.json está sincronizado.\n");
    exit(0);
}

file_put_contents($target, $json);
fwrite(STDOUT, "Gerado {$target} com ".count($paths)." paths.\n");

function operationId(string $method, string $path): string
{
    $parts = preg_split('/[^A-Za-z0-9]+/', trim($path, '/')) ?: [];
    $parts = array_values(array_filter($parts, static fn (string $part): bool => $part !== 'api' && $part !== 'v1'));
    $suffix = implode('', array_map(
        static fn (string $part): string => ucfirst($part),
        $parts,
    ));

    return $method.$suffix;
}

function actionSummary(Route $route): string
{
    $action = $route->getActionName();
    if ($action === 'Closure') {
        return 'Operação Laravel';
    }

    $action = str_replace('\\', '/', $action);
    $action = substr($action, strrpos($action, '/') + 1);

    return str_replace('@', ' ', $action);
}

/**
 * @param  array<string, mixed>  $operation
 */
function applyKnownContract(array &$operation, string $method, string $path): void
{
    if ($method === 'get' && $path === '/api/v1/me') {
        $operation['responses'] = jsonResponse('#/components/schemas/MeResponse');

        return;
    }

    if ($path !== '/api/v1/list-filters' && $path !== '/api/v1/list-filters/{listFilter}') {
        return;
    }

    if ($method === 'get') {
        $operation['parameters'][] = [
            'name' => 'surface',
            'in' => 'query',
            'required' => true,
            'schema' => ['$ref' => '#/components/schemas/SavedListSurface'],
        ];
        $operation['responses'] = jsonResponse('#/components/schemas/SavedListFilterCollection');

        return;
    }

    if ($method === 'delete') {
        $operation['responses'] = ['204' => ['description' => 'Filtro removido.']];

        return;
    }

    $schema = $method === 'post'
        ? '#/components/schemas/CreateSavedListFilterBody'
        : '#/components/schemas/UpdateSavedListFilterBody';
    $operation['requestBody'] = [
        'required' => true,
        'content' => [
            'application/json' => ['schema' => ['$ref' => $schema]],
        ],
    ];
    $operation['responses'] = jsonResponse(
        '#/components/schemas/SavedListFilterResponse',
        $method === 'post' ? '201' : '200',
    );
}

/**
 * @return array<string, array<string, mixed>>
 */
function jsonResponse(string $schema, string $status = '200'): array
{
    return [
        $status => [
            'description' => 'Resposta canônica.',
            'content' => [
                'application/json' => ['schema' => ['$ref' => $schema]],
            ],
        ],
    ];
}

/**
 * @return array<string, array<string, mixed>>
 */
function schemas(): array
{
    return [
        'JsonResponse' => ['type' => 'object', 'additionalProperties' => true],
        'TenantRole' => ['type' => 'string', 'enum' => ['tenant_admin', 'tenant_user']],
        'PlatformRole' => ['type' => 'string', 'enum' => ['platform_admin']],
        'TenantAccessMode' => ['type' => 'string', 'enum' => ['membership', 'platform_privileged']],
        'Tenant' => closedObject(
            ['id', 'name', 'slug'],
            [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
            ],
        ),
        'TenantMembership' => closedObject(
            ['tenant_id', 'tenant_name', 'tenant_slug', 'role', 'is_current'],
            [
                'tenant_id' => ['type' => 'integer'],
                'tenant_name' => nullableString(),
                'tenant_slug' => nullableString(),
                'role' => ['$ref' => '#/components/schemas/TenantRole'],
                'is_current' => ['type' => 'boolean'],
            ],
        ),
        'PermissionProfileSummary' => closedObject(
            ['id', 'key', 'name', 'is_system', 'is_active'],
            [
                'id' => ['type' => 'integer'],
                'key' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'is_system' => ['type' => 'boolean'],
                'is_active' => ['type' => 'boolean'],
            ],
        ),
        'AssistantAvailability' => closedObject(
            ['enabled'],
            ['enabled' => ['type' => 'boolean']],
        ),
        'MeUser' => closedObject(
            [
                'id', 'name', 'email', 'platform_role', 'tenant_role', 'real_tenant_role',
                'effective_permissions', 'permission_profile', 'access_mode',
                'has_real_membership', 'context_status', 'current_tenant',
                'platform_organization_name', 'default_tenant_id', 'memberships', 'assistant',
            ],
            [
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string', 'format' => 'email'],
                'platform_role' => nullableRef('#/components/schemas/PlatformRole'),
                'tenant_role' => nullableRef('#/components/schemas/TenantRole'),
                'real_tenant_role' => nullableRef('#/components/schemas/TenantRole'),
                'effective_permissions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'permission_profile' => nullableRef('#/components/schemas/PermissionProfileSummary'),
                'access_mode' => nullableRef('#/components/schemas/TenantAccessMode'),
                'has_real_membership' => ['type' => 'boolean'],
                'context_status' => ['type' => 'string', 'enum' => ['ok', 'tenant_context_required']],
                'current_tenant' => nullableRef('#/components/schemas/Tenant'),
                'platform_organization_name' => nullableString(),
                'default_tenant_id' => ['type' => ['integer', 'null']],
                'memberships' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/TenantMembership'],
                ],
                'assistant' => ['$ref' => '#/components/schemas/AssistantAvailability'],
            ],
        ),
        'MeResponse' => closedObject(
            ['data'],
            ['data' => ['$ref' => '#/components/schemas/MeUser']],
        ),
        'SavedListSurface' => ['type' => 'string', 'enum' => SavedListFilter::SURFACES],
        'SavedFilterVisibility' => ['type' => 'string', 'enum' => ['personal', 'tenant']],
        'DataTableFilterModel' => closedObject(
            ['key', 'operator', 'value'],
            [
                'key' => ['type' => 'string'],
                'operator' => ['type' => 'string', 'enum' => ['eq', 'contains', 'between', 'in']],
                'value' => ['type' => ['string', 'integer', 'boolean']],
                'label' => ['type' => 'string'],
            ],
        ),
        'MonitoringSavedFilterPayload' => closedObject(
            ['q', 'filters'],
            [
                'q' => ['type' => 'string'],
                'filters' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/DataTableFilterModel'],
                ],
            ],
        ),
        'ClientsSavedFilterPayload' => closedStringObject([
            'q', 'status', 'operational_filter', 'category_ids', 'tax_regimes', 'procuracao_statuses',
        ]),
        'DocsSavedFilterPayload' => closedStringObject([
            'q', 'kind', 'direction', 'client_id', 'establishment_id', 'issuer_cnpj',
            'taker_cnpj', 'fiscal_role', 'acquisition_source', 'artifact_quality',
            'coverage_status', 'competence', 'issued_from', 'issued_to', 'status',
            'missing_party_name',
        ], []),
        'WorkQueueSavedFilterPayload' => closedObject(
            ['tab', 'q', 'department_id', 'assignee_membership_id', 'client_id', 'scope'],
            [
                'tab' => ['type' => 'string'],
                'q' => ['type' => 'string'],
                'department_id' => ['type' => ['integer', 'null']],
                'assignee_membership_id' => ['type' => ['integer', 'null']],
                'client_id' => ['type' => ['integer', 'null']],
                'scope' => ['type' => 'string'],
                'per_page' => ['type' => 'integer', 'minimum' => 1],
            ],
        ),
        'WorkProcessesSavedFilterPayload' => closedObject(
            ['q', 'competence', 'status', 'client_id', 'department_id'],
            [
                'q' => ['type' => 'string'],
                'competence' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'client_id' => ['type' => ['integer', 'null']],
                'department_id' => ['type' => ['integer', 'null']],
                'group' => ['type' => 'string', 'enum' => ['client']],
            ],
        ),
        'ClosingSavedFilterPayload' => closedStringObject([
            'competence', 'band', 'model', 'root', 'source', 'client_id',
        ]),
        'SavedListFilterPayload' => [
            'oneOf' => array_map(
                static fn (string $name): array => ['$ref' => '#/components/schemas/'.$name],
                [
                    'MonitoringSavedFilterPayload',
                    'ClientsSavedFilterPayload',
                    'DocsSavedFilterPayload',
                    'WorkQueueSavedFilterPayload',
                    'WorkProcessesSavedFilterPayload',
                    'ClosingSavedFilterPayload',
                ],
            ),
        ],
        'SavedListFilterAuthor' => closedObject(
            ['id', 'name'],
            ['id' => ['type' => 'integer'], 'name' => nullableString()],
        ),
        'SavedListFilterPermissions' => closedObject(
            ['update', 'delete', 'share'],
            [
                'update' => ['type' => 'boolean'],
                'delete' => ['type' => 'boolean'],
                'share' => ['type' => 'boolean'],
            ],
        ),
        'SavedListFilter' => closedObject(
            [
                'id', 'surface', 'name', 'visibility', 'schema_version', 'payload',
                'author', 'permissions', 'created_at', 'updated_at',
            ],
            [
                'id' => ['type' => 'integer'],
                'surface' => ['$ref' => '#/components/schemas/SavedListSurface'],
                'name' => ['type' => 'string'],
                'visibility' => ['$ref' => '#/components/schemas/SavedFilterVisibility'],
                'schema_version' => ['type' => 'integer', 'const' => 1],
                'payload' => ['$ref' => '#/components/schemas/SavedListFilterPayload'],
                'author' => ['$ref' => '#/components/schemas/SavedListFilterAuthor'],
                'permissions' => ['$ref' => '#/components/schemas/SavedListFilterPermissions'],
                'created_at' => nullableString('date-time'),
                'updated_at' => nullableString('date-time'),
            ],
        ),
        'CreateSavedListFilterBody' => closedObject(
            ['surface', 'name', 'visibility', 'payload'],
            [
                'surface' => ['$ref' => '#/components/schemas/SavedListSurface'],
                'name' => ['type' => 'string', 'maxLength' => 120],
                'visibility' => ['$ref' => '#/components/schemas/SavedFilterVisibility'],
                'payload' => ['$ref' => '#/components/schemas/SavedListFilterPayload'],
            ],
        ),
        'UpdateSavedListFilterBody' => closedObject(
            [],
            [
                'name' => ['type' => 'string', 'maxLength' => 120],
                'visibility' => ['$ref' => '#/components/schemas/SavedFilterVisibility'],
                'payload' => ['$ref' => '#/components/schemas/SavedListFilterPayload'],
            ],
        ),
        'SavedListFilterResponse' => closedObject(
            ['data'],
            ['data' => ['$ref' => '#/components/schemas/SavedListFilter']],
        ),
        'SavedListFilterCollection' => closedObject(
            ['data'],
            [
                'data' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/SavedListFilter'],
                ],
            ],
        ),
    ];
}

/**
 * @param  list<string>  $required
 * @param  array<string, array<string, mixed>>  $properties
 * @return array<string, mixed>
 */
function closedObject(array $required, array $properties): array
{
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'required' => $required,
        'properties' => $properties,
    ];
}

/**
 * @param  list<string>  $properties
 * @param  list<string>|null  $required
 * @return array<string, mixed>
 */
function closedStringObject(array $properties, ?array $required = null): array
{
    return closedObject(
        $required ?? $properties,
        array_fill_keys($properties, ['type' => 'string']),
    );
}

/**
 * @return array<string, mixed>
 */
function nullableString(?string $format = null): array
{
    $schema = ['type' => ['string', 'null']];
    if ($format !== null) {
        $schema['format'] = $format;
    }

    return $schema;
}

/**
 * @return array<string, mixed>
 */
function nullableRef(string $ref): array
{
    return ['oneOf' => [['$ref' => $ref], ['type' => 'null']]];
}

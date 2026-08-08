<?php

declare(strict_types=1);

use App\Enums\Communication\ConversationListSort;
use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
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

    if ($path === '/api/v1/communication/contacts') {
        if ($method === 'get') {
            $operation['parameters'][] = inboxContextParameter();
            $operation['responses'] = jsonResponse(
                '#/components/schemas/CommunicationContactCollection',
            );
        } elseif ($method === 'post') {
            $operation['responses'] = jsonResponse(
                '#/components/schemas/CommunicationContactResponse',
                '201',
            );
        }

        return;
    }

    if ($path === '/api/v1/communication/contacts/search' && $method === 'post') {
        $operation['requestBody'] = [
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/CommunicationContactSearchBody'],
                ],
            ],
        ];
        $operation['responses'] = jsonResponse(
            '#/components/schemas/CommunicationContactCollection',
        );

        return;
    }

    if ($path === '/api/v1/communication/contacts/{contact}'
        && in_array($method, ['get', 'patch'], true)) {
        if ($method === 'get') {
            $operation['parameters'][] = inboxContextParameter();
        }
        $operation['responses'] = jsonResponse(
            '#/components/schemas/CommunicationContactResponse',
        );

        return;
    }

    if ($path === '/api/v1/communication/profile-pictures/{profile}/{version}'
        && $method === 'get') {
        $operation['parameters'] = [
            [
                'name' => 'profile',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'integer', 'minimum' => 1],
            ],
            [
                'name' => 'version',
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'integer', 'minimum' => 1],
            ],
            [
                'name' => 'If-None-Match',
                'in' => 'header',
                'required' => false,
                'schema' => ['type' => 'string'],
                'description' => 'ETag previamente recebido para revalidação privada.',
            ],
        ];
        $image = ['schema' => ['type' => 'string', 'format' => 'binary']];
        $operation['responses'] = [
            '200' => [
                'description' => 'Foto de perfil privada e autorizada.',
                'headers' => [
                    'ETag' => ['schema' => ['type' => 'string']],
                    'Cache-Control' => [
                        'schema' => [
                            'type' => 'string',
                            'const' => 'private, no-cache, must-revalidate',
                        ],
                    ],
                    'X-Content-Type-Options' => [
                        'schema' => ['type' => 'string', 'const' => 'nosniff'],
                    ],
                ],
                'content' => [
                    'image/jpeg' => $image,
                    'image/png' => $image,
                    'image/webp' => $image,
                ],
            ],
            '304' => ['description' => 'Asset autorizado não foi modificado.'],
            '404' => ['description' => 'Asset ausente, obsoleto ou não autorizado.'],
            '429' => ['description' => 'Limite de revalidações da imagem excedido.'],
        ];

        return;
    }

    if ($path === '/api/v1/communication/conversations') {
        if ($method === 'get') {
            $operation['parameters'][] = [
                'name' => 'contact_id',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1],
            ];
            $operation['parameters'][] = [
                'name' => 'snapshot',
                'in' => 'query',
                'required' => false,
                'description' => 'Cria uma foto estável na primeira página de uma consulta unread=true.',
                'schema' => ['type' => 'boolean'],
            ];
            $operation['parameters'][] = [
                'name' => 'snapshot_token',
                'in' => 'query',
                'required' => false,
                'description' => 'Token opaco devolvido pela primeira página para paginação e reconciliação da mesma foto.',
                'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 128],
            ];
            $operation['responses'] = jsonResponse(
                '#/components/schemas/CommunicationConversationCollection',
            )
                + jsonResponse('#/components/schemas/JsonResponse', '410')
                + jsonResponse('#/components/schemas/JsonResponse', '422')
                + jsonResponse('#/components/schemas/JsonResponse', '503');
        } elseif ($method === 'post') {
            $operation['parameters'][] = [
                'name' => 'Idempotency-Key',
                'in' => 'header',
                'required' => true,
                'schema' => [
                    'type' => 'string',
                    'minLength' => 8,
                    'maxLength' => 128,
                    'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$',
                ],
            ];
            $operation['requestBody'] = [
                'required' => true,
                'content' => [
                    'multipart/form-data' => [
                        'schema' => ['$ref' => '#/components/schemas/StartCommunicationConversationBody'],
                    ],
                ],
            ];
            $operation['responses'] = jsonResponse('#/components/schemas/StartCommunicationConversationResponse', '200')
                + jsonResponse('#/components/schemas/StartCommunicationConversationResponse', '202')
                + jsonResponse('#/components/schemas/JsonResponse', '409')
                + jsonResponse('#/components/schemas/JsonResponse', '422');
        }

        return;
    }

    if ($path === '/api/v1/communication/outbound-capabilities' && $method === 'get') {
        $operation['parameters'][] = [
            'name' => 'inbox_id',
            'in' => 'query',
            'required' => false,
            'schema' => ['type' => 'integer', 'minimum' => 1],
        ];
        $operation['responses'] = jsonResponse(
            '#/components/schemas/CommunicationOutboundCapabilitiesResponse',
        );

        return;
    }

    if ($path === '/api/v1/communication/gifs/search' && $method === 'get') {
        $operation['parameters'] = [
            [
                'name' => 'inbox_id',
                'in' => 'query',
                'required' => true,
                'schema' => ['type' => 'integer', 'minimum' => 1],
            ],
            [
                'name' => 'q',
                'in' => 'query',
                'required' => true,
                'schema' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 100],
            ],
            [
                'name' => 'limit',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'default' => 20],
            ],
        ];
        $operation['responses'] = jsonResponse('#/components/schemas/CommunicationGifSearchResponse')
            + jsonResponse('#/components/schemas/JsonResponse', '403')
            + jsonResponse('#/components/schemas/JsonResponse', '422')
            + jsonResponse('#/components/schemas/JsonResponse', '503');

        return;
    }

    if ($path === '/api/v1/communication/gifs/{token}/preview' && $method === 'get') {
        $operation['parameters'] = [[
            'name' => 'token',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{40}$'],
        ]];
        $binary = ['schema' => ['type' => 'string', 'format' => 'binary']];
        $operation['responses'] = [
            '200' => [
                'description' => 'Prévia privada e autorizada do GIF remoto allowlisted.',
                'headers' => [
                    'Cache-Control' => ['schema' => privateNoStoreCacheControlSchema()],
                    'X-Content-Type-Options' => ['schema' => ['type' => 'string', 'const' => 'nosniff']],
                ],
                'content' => [
                    'image/gif' => $binary,
                    'image/webp' => $binary,
                    'image/jpeg' => $binary,
                    'image/png' => $binary,
                ],
            ],
            '404' => ['description' => 'Prévia ausente, expirada ou não autorizada.'],
        ];

        return;
    }

    if ($path === '/api/v1/communication/gifs/{token}/asset' && $method === 'get') {
        $operation['parameters'] = [[
            'name' => 'token',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{40}$'],
        ]];
        $binary = ['schema' => ['type' => 'string', 'format' => 'binary']];
        $operation['responses'] = [
            '200' => [
                'description' => 'Asset GIF privado, autorizado e revalidado para materialização antes do envio.',
                'headers' => [
                    'Cache-Control' => ['schema' => privateNoStoreCacheControlSchema()],
                    'X-Content-Type-Options' => ['schema' => ['type' => 'string', 'const' => 'nosniff']],
                ],
                'content' => [
                    'video/mp4' => $binary,
                    'video/webm' => $binary,
                ],
            ],
            '404' => ['description' => 'Asset ausente, expirado, não autorizado ou de origem não allowlisted.'],
        ] + jsonResponse('#/components/schemas/JsonResponse', '503');

        return;
    }

    if ($method === 'get' && in_array($path, [
        '/api/v1/communication/contacts/{contact}/shared-content',
        '/api/v1/communication/conversations/{conversation}/shared-content',
    ], true)) {
        $operation['parameters'] = [
            ...($operation['parameters'] ?? []),
            [
                'name' => 'category',
                'in' => 'query',
                'required' => true,
                'schema' => ['$ref' => '#/components/schemas/CommunicationSharedContentCategory'],
            ],
            [
                'name' => 'limit',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 30],
            ],
            [
                'name' => 'cursor',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'string', 'maxLength' => 1024],
            ],
        ];
        if ($path === '/api/v1/communication/contacts/{contact}/shared-content') {
            $operation['parameters'][] = [
                'name' => 'inbox_id',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1],
            ];
        }
        $operation['responses'] = jsonResponseWithPrivateHeaders(
            '#/components/schemas/CommunicationSharedContentCollection',
        );

        return;
    }

    if ($path === '/api/v1/communication/conversation-bulk-operations' && $method === 'post') {
        $operation['parameters'] = [[
            'name' => 'Idempotency-Key', 'in' => 'header', 'required' => true,
            'schema' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$'],
        ]];
        $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ConversationBulkOperationBody']]]];
        $operation['responses'] = jsonResponse('#/components/schemas/ConversationBulkOperationResponse', '202')
            + jsonResponse('#/components/schemas/JsonResponse', '409')
            + jsonResponse('#/components/schemas/JsonResponse', '422');

        return;
    }
    if ($path === '/api/v1/communication/conversation-bulk-operations/{operation}' && $method === 'get') {
        $operation['responses'] = jsonResponse('#/components/schemas/ConversationBulkOperationResponse');

        return;
    }
    if ($path === '/api/v1/communication/conversation-bulk-operations/{operation}/items' && $method === 'get') {
        $operation['parameters'] = [...($operation['parameters'] ?? []),
            ['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1]],
            ['name' => 'per_page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
        ];
        $operation['responses'] = jsonResponse('#/components/schemas/ConversationBulkOperationItemCollection');

        return;
    }
    if ($path === '/api/v1/communication/conversation-list-preferences') {
        if ($method === 'put') {
            $operation['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ConversationListPreferenceBody']]]];
            $operation['responses'] = jsonResponse('#/components/schemas/ConversationListPreferenceResponse') + jsonResponse('#/components/schemas/JsonResponse', '422');
        } elseif ($method === 'get') {
            $operation['responses'] = jsonResponse('#/components/schemas/ConversationListPreferenceResponse');
        }

        return;
    }

    if ($path === '/api/v1/communication/conversations/{conversation}'
        && in_array($method, ['get', 'patch'], true)) {
        $operation['responses'] = jsonResponse(
            '#/components/schemas/CommunicationConversationResponse',
        );

        return;
    }

    if ($path === '/api/v1/communication/conversations/{conversation}/messages'
        && $method === 'get') {
        $operation['parameters'] = [
            ...($operation['parameters'] ?? []),
            [
                'name' => 'anchor',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'string', 'enum' => ['latest', 'first_unread', 'message']],
            ],
            [
                'name' => 'message_id',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1],
            ],
        ];
        $operation['responses'] = jsonResponse(
            '#/components/schemas/CommunicationMessageCollection',
        );

        return;
    }

    if ($path === '/api/v1/communication/conversations/{conversation}/messages'
        && $method === 'post') {
        $operation['requestBody'] = [
            'required' => true,
            'content' => [
                'application/json' => ['schema' => ['$ref' => '#/components/schemas/CommunicationSendMessageBody']],
                'multipart/form-data' => ['schema' => ['$ref' => '#/components/schemas/CommunicationSendMessageBody']],
            ],
        ];
        $operation['responses'] = jsonResponse('#/components/schemas/CommunicationMessageResponse', '200')
            + jsonResponse('#/components/schemas/CommunicationMessageResponse', '201')
            + jsonResponse('#/components/schemas/CommunicationMessageResponse', '202')
            + jsonResponse('#/components/schemas/JsonResponse', '422');

        return;
    }

    if ($path === '/api/v1/communication/inboxes/{inbox}/stickers' && $method === 'get') {
        $operation['parameters'] = [
            ...($operation['parameters'] ?? []),
            [
                'name' => 'favorite',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'string', 'enum' => ['app', 'device', 'any']],
            ],
            [
                'name' => 'source',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'string', 'enum' => ['DEVICE_RECENT', 'DEVICE_FAVORITE', 'DEVICE_MESSAGE', 'LOCAL_IMPORT']],
            ],
            [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
        ];
        $operation['responses'] = jsonResponse('#/components/schemas/CommunicationStickerLibraryCollection');

        return;
    }

    if ($path === '/api/v1/communication/inboxes/{inbox}/stickers/import' && $method === 'post') {
        $operation['requestBody'] = [
            'required' => true,
            'content' => [
                'multipart/form-data' => [
                    'schema' => closedObject(
                        ['file'],
                        ['file' => ['type' => 'string', 'format' => 'binary']],
                    ),
                ],
            ],
        ];
        $operation['responses'] = jsonResponse('#/components/schemas/CommunicationStickerLibraryItemResponse', '201');

        return;
    }

    if ($path === '/api/v1/communication/inboxes/{inbox}/stickers/status' && $method === 'get') {
        $operation['responses'] = jsonResponse('#/components/schemas/CommunicationStickerLibraryStatusResponse');

        return;
    }

    if ($path === '/api/v1/communication/stickers/{sticker}/preview' && $method === 'get') {
        $operation['responses'] = [
            '200' => [
                'description' => 'Preview privado autorizado da figurinha.',
                'headers' => [
                    'Cache-Control' => [
                        'schema' => ['type' => 'string', 'const' => 'private, no-store, max-age=0'],
                    ],
                ],
                'content' => [
                    'image/webp' => ['schema' => ['type' => 'string', 'format' => 'binary']],
                ],
            ],
            '404' => ['description' => 'Figurinha ausente ou não autorizada.'],
            '409' => ['description' => 'Objeto privado temporariamente ilegível.'],
        ];

        return;
    }

    if ($path === '/api/v1/communication/stickers/{sticker}/favorite' && $method === 'put') {
        $operation['requestBody'] = [
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => closedObject(
                        ['favorite'],
                        [
                            'favorite' => [
                                'type' => 'boolean',
                                'description' => 'KontiveHub favorite only; does not mutate WhatsApp mobile favorites.',
                            ],
                        ],
                    ),
                ],
            ],
        ];
        $operation['responses'] = jsonResponse('#/components/schemas/CommunicationStickerLibraryItemResponse');

        return;
    }

    if ($path === '/api/v1/communication/stickers/{sticker}' && $method === 'delete') {
        $operation['responses'] = [
            '204' => ['description' => 'Referência removida da biblioteca do inbox.'],
        ];

        return;
    }

    if ($path === '/api/v1/communication/conversations/{conversation}/message-batches'
        && $method === 'post') {
        $operation['requestBody'] = [
            'required' => true,
            'content' => [
                'multipart/form-data' => ['schema' => ['$ref' => '#/components/schemas/CommunicationMessageBatchBody']],
            ],
        ];
        $operation['responses'] = jsonResponse('#/components/schemas/CommunicationMessageBatchResponse', '200')
            + jsonResponse('#/components/schemas/CommunicationMessageBatchResponse', '202')
            + jsonResponse('#/components/schemas/JsonResponse', '409')
            + jsonResponse('#/components/schemas/JsonResponse', '422');

        return;
    }

    if ($path === '/api/v1/communication/conversations/{conversation}/messages/{message}/contacts/{contactIndex}/save'
        && $method === 'post') {
        $operation['requestBody'] = [
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/CommunicationSaveSharedContactBody'],
                ],
            ],
        ];
        $operation['responses'] = jsonResponse('#/components/schemas/CommunicationSaveSharedContactResponse', '200')
            + jsonResponse('#/components/schemas/CommunicationSaveSharedContactResponse', '201')
            + jsonResponse('#/components/schemas/JsonResponse', '403')
            + jsonResponse('#/components/schemas/JsonResponse', '404')
            + jsonResponse('#/components/schemas/JsonResponse', '422');

        return;
    }

    if ($method === 'get' && in_array($path, [
        '/api/v1/communication/attachments/{attachment}/download',
        '/api/v1/communication/attachments/{attachment}/preview',
    ], true)) {
        $operation['parameters'][] = [
            'name' => 'Range',
            'in' => 'header',
            'required' => false,
            'schema' => ['type' => 'string', 'pattern' => '^bytes=(?:\\d+-\\d*|-\\d+)$'],
            'description' => 'Um único intervalo de bytes para reprodução privada de áudio/vídeo.',
        ];
        $binary = ['content' => ['application/octet-stream' => ['schema' => ['type' => 'string', 'format' => 'binary']]]];
        $operation['responses'] = [
            '200' => ['description' => 'Objeto privado completo.', ...$binary],
            '206' => [
                'description' => 'Intervalo privado autorizado.',
                'headers' => [
                    'Accept-Ranges' => ['schema' => ['type' => 'string', 'const' => 'bytes']],
                    'Content-Range' => ['schema' => ['type' => 'string']],
                ],
                ...$binary,
            ],
            '404' => ['description' => 'Objeto ausente ou não autorizado.'],
            '416' => ['description' => 'Intervalo de bytes inválido.'],
        ];

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

/** @return array<string, mixed> */
function inboxContextParameter(): array
{
    return [
        'name' => 'inbox_id',
        'in' => 'query',
        'required' => false,
        'schema' => ['type' => 'integer', 'minimum' => 1],
        'description' => 'Contexto autorizado de inbox para nome e foto observados.',
    ];
}

/** @return array<string, string> */
function privateNoStoreCacheControlSchema(): array
{
    return [
        'type' => 'string',
        'pattern' => '(?=.*\\bprivate\\b)(?=.*\\bno-store\\b)',
    ];
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
function jsonResponseWithPrivateHeaders(string $schema): array
{
    $response = jsonResponse($schema);
    $response['200']['headers'] = [
        'Cache-Control' => [
            'description' => 'Resposta privada e não armazenável.',
            'schema' => ['type' => 'string', 'const' => 'private, no-store, max-age=0'],
        ],
        'Pragma' => [
            'description' => 'Compatibilidade com caches HTTP antigos.',
            'schema' => ['type' => 'string', 'const' => 'no-cache'],
        ],
    ];

    return $response;
}

/**
 * @return array<string, array<string, mixed>>
 */
function schemas(): array
{
    return [
        'JsonResponse' => ['type' => 'object', 'additionalProperties' => true],
        'ConversationBulkOperationBody' => ['type' => 'object', 'required' => ['action', 'items'], 'properties' => ['action' => ['type' => 'string'], 'params' => ['type' => 'object'], 'items' => ['type' => 'array']], 'additionalProperties' => false],
        'ConversationBulkOperationResponse' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'object', 'additionalProperties' => true]], 'additionalProperties' => false],
        'ConversationBulkOperationItemCollection' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]], 'meta' => ['type' => 'object', 'additionalProperties' => true]], 'additionalProperties' => false],
        'ConversationListPreferenceBody' => ['type' => 'object', 'properties' => ['status' => ['type' => 'string'], 'sort_by' => ['type' => 'string']], 'additionalProperties' => false],
        'ConversationListPreferenceResponse' => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'object', 'additionalProperties' => true]], 'additionalProperties' => false],
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
        'CommunicationIdentityLink' => closedObject(
            [
                'id', 'client_id', 'client_name', 'client_contact_id',
                'client_contact_name', 'is_primary', 'receives_automatic',
            ],
            [
                'id' => ['type' => 'integer'],
                'client_id' => ['type' => 'integer'],
                'client_name' => nullableString(),
                'client_contact_id' => ['type' => ['integer', 'null']],
                'client_contact_name' => nullableString(),
                'is_primary' => ['type' => 'boolean'],
                'receives_automatic' => ['type' => 'boolean'],
            ],
        ),
        'CommunicationContactIdentity' => closedObject(
            ['id', 'channel', 'address_masked', 'phone', 'is_active', 'links'],
            [
                'id' => ['type' => 'integer'],
                'channel' => ['type' => 'string'],
                'address_masked' => ['type' => 'string'],
                'phone' => [
                    'type' => ['string', 'null'],
                    'pattern' => '^\\+[1-9]\\d{7,14}$',
                    'description' => 'E.164 autorizado por communication.view; null quando não apresentável.',
                ],
                'is_active' => ['type' => 'boolean'],
                'links' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/CommunicationIdentityLink'],
                ],
            ],
        ),
        'CommunicationContact' => closedObject(
            ['id', 'name', 'is_provisional', 'is_active', 'profile_picture_url', 'profile_picture_state', 'identities', 'purged_at'],
            [
                'id' => ['type' => 'integer'],
                'name' => nullableString(),
                'display_name' => nullableString(),
                'display_name_source' => [
                    'type' => ['string', 'null'],
                    'enum' => [
                        'MANUAL_CONTACT', 'CLIENT_CONTACT', 'WHATSAPP_ADDRESS_BOOK',
                        'WHATSAPP_USER_INFO', 'WHATSAPP_BUSINESS', 'WHATSAPP_PUSH_NAME',
                        'LEGACY_PROVISIONAL', 'MASKED_ADDRESS', 'OPAQUE_ID', null,
                    ],
                ],
                'display_name_state' => [
                    'type' => ['string', 'null'],
                    'enum' => ['CURATED', 'OBSERVED', 'FALLBACK', null],
                ],
                'display_name_inbox_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'is_provisional' => ['type' => 'boolean'],
                'is_active' => ['type' => 'boolean'],
                'profile_picture_url' => [
                    'type' => ['string', 'null'],
                    'format' => 'uri-reference',
                    'description' => 'URL Laravel same-origin; null enquanto o asset privado não estiver pronto.',
                ],
                'profile_picture_state' => ['type' => 'string', 'enum' => ['UNKNOWN', 'PENDING', 'READY', 'UNAVAILABLE', 'FAILED']],
                'profile_picture_inbox_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'identities' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/CommunicationContactIdentity'],
                ],
                'purged_at' => nullableString('date-time'),
            ],
        ),
        'CommunicationPaginationMeta' => closedObject(
            ['current_page', 'last_page', 'total'],
            [
                'current_page' => ['type' => 'integer', 'minimum' => 1],
                'last_page' => ['type' => 'integer', 'minimum' => 1],
                'total' => ['type' => 'integer', 'minimum' => 0],
            ],
        ),
        'CommunicationConversationPaginationMeta' => closedObject(
            ['current_page', 'last_page', 'total'],
            [
                'current_page' => ['type' => 'integer', 'minimum' => 1],
                'last_page' => ['type' => 'integer', 'minimum' => 1],
                'total' => ['type' => 'integer', 'minimum' => 0],
                'snapshot_token' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 128,
                    'description' => 'Presente somente na paginação por snapshot unread.',
                ],
                'snapshot_expires_at' => [
                    'type' => 'string',
                    'format' => 'date-time',
                    'description' => 'Expiração absoluta do snapshot; acessos não renovam este instante.',
                ],
            ],
        ),
        'CommunicationContactResponse' => closedObject(
            ['data'],
            ['data' => ['$ref' => '#/components/schemas/CommunicationContact']],
        ),
        'CommunicationContactCollection' => closedObject(
            ['data', 'meta'],
            [
                'data' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/CommunicationContact'],
                ],
                'meta' => ['$ref' => '#/components/schemas/CommunicationPaginationMeta'],
            ],
        ),
        'CommunicationContactSearchBody' => closedObject(
            ['q'],
            [
                'q' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'inbox_id' => ['type' => 'integer', 'minimum' => 1],
                'is_active' => ['type' => 'boolean'],
                'include_inactive' => ['type' => 'boolean'],
                'is_provisional' => ['type' => 'boolean'],
                'linked' => ['type' => 'boolean'],
                'sort' => ['type' => 'string', 'enum' => ['name', 'id', 'created_at']],
                'sort_direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                'page' => ['type' => 'integer', 'minimum' => 1],
            ],
        ),
        'CommunicationConversationContact' => closedObject(
            ['id', 'name', 'is_provisional', 'address_masked', 'profile_picture_url', 'profile_picture_state', 'phone', 'address'],
            [
                'id' => ['type' => 'integer'],
                'name' => nullableString(),
                'is_provisional' => ['type' => ['boolean', 'null']],
                'address_masked' => ['type' => 'string'],
                'profile_picture_url' => [
                    'type' => ['string', 'null'],
                    'format' => 'uri-reference',
                    'description' => 'URL Laravel same-origin; nunca contém a URL do provider.',
                ],
                'profile_picture_state' => ['type' => 'string', 'enum' => ['UNKNOWN', 'PENDING', 'READY', 'UNAVAILABLE', 'FAILED']],
                'phone' => [
                    'type' => ['string', 'null'],
                    'pattern' => '^\\+[1-9]\\d{7,14}$',
                ],
                'address' => [
                    'type' => ['string', 'null'],
                    'pattern' => '^\\+[1-9]\\d{7,14}$',
                    'x-kontivehub-lifecycle' => 'alias',
                    'description' => 'Alias de phone; nunca contém endereço técnico.',
                ],
            ],
        ),
        'CommunicationMessageAvailabilityState' => [
            'type' => 'string',
            'enum' => [
                'AVAILABLE',
                'UNSUPPORTED',
                'MEDIA_RETRY_AVAILABLE',
                'MEDIA_REQUESTED',
                'MEDIA_FAILED',
                'UNAVAILABLE',
            ],
        ],
        'CommunicationMessageAvailability' => closedObject(
            ['state', 'recoverable'],
            [
                'state' => ['$ref' => '#/components/schemas/CommunicationMessageAvailabilityState'],
                'recoverable' => ['type' => 'boolean'],
            ],
        ),
        'CommunicationMessageLinkPreview' => closedObject(
            ['url'],
            [
                'url' => ['type' => 'string', 'format' => 'uri', 'pattern' => '^https?://'],
                'title' => ['type' => 'string', 'maxLength' => 4096],
                'description' => ['type' => 'string', 'maxLength' => 8192],
            ],
        ),
        'CommunicationMessageLocation' => closedObject(
            ['latitude', 'longitude'],
            [
                'latitude' => ['type' => 'number', 'minimum' => -90, 'maximum' => 90],
                'longitude' => ['type' => 'number', 'minimum' => -180, 'maximum' => 180],
                'name' => ['type' => 'string'],
                'address' => ['type' => 'string'],
                'caption' => ['type' => 'string'],
                'live' => ['type' => 'boolean'],
                'accuracy_meters' => ['type' => 'integer', 'minimum' => 0],
                'sequence' => ['type' => 'integer', 'minimum' => 0],
            ],
        ),
        'CommunicationSharedPhone' => closedObject(
            ['label', 'phone'],
            [
                'label' => ['type' => 'string', 'maxLength' => 40],
                'phone' => ['type' => 'string', 'pattern' => '^\\+[1-9]\\d{7,14}$'],
            ],
        ),
        'CommunicationSharedContact' => closedObject(
            ['display_name', 'vcard', 'phones'],
            [
                'display_name' => ['type' => 'string', 'maxLength' => 1024],
                'vcard' => ['type' => 'string', 'maxLength' => 65_536],
                'phones' => ['type' => 'array', 'maxItems' => 10, 'items' => ['$ref' => '#/components/schemas/CommunicationSharedPhone']],
            ],
        ),
        'CommunicationMessagePoll' => closedObject(
            ['name', 'options'],
            [
                'name' => ['type' => 'string', 'maxLength' => 4096],
                'options' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 12, 'items' => ['type' => 'string', 'maxLength' => 1024]],
                'selectable_options' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 12],
            ],
        ),
        'CommunicationMessageInteractive' => closedObject(
            ['mode'],
            [
                'mode' => ['type' => 'string', 'pattern' => '^[A-Z][A-Z0-9_]{1,63}$'],
                'title' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'selected_id' => ['type' => 'string'],
                'display_text' => ['type' => 'string'],
                'name' => ['type' => 'string'],
            ],
        ),
        'CommunicationMessageEvent' => closedObject(
            ['title', 'start_at', 'timezone'],
            [
                'title' => ['type' => 'string', 'maxLength' => 512],
                'description' => ['type' => 'string', 'maxLength' => 2048],
                'start_at' => ['type' => 'string', 'format' => 'date-time'],
                'end_at' => ['type' => 'string', 'format' => 'date-time'],
                'timezone' => ['type' => 'string', 'maxLength' => 64],
                'location_name' => ['type' => 'string', 'maxLength' => 512],
                'location_address' => ['type' => 'string', 'maxLength' => 2048],
                'participation_enabled' => ['type' => 'boolean'],
            ],
        ),
        'CommunicationRichCardFact' => closedObject(
            ['label', 'value'],
            ['label' => ['type' => 'string', 'maxLength' => 64], 'value' => ['type' => 'string', 'maxLength' => 1024]],
        ),
        'CommunicationRichCard' => closedObject(
            ['category', 'title'],
            [
                'category' => ['type' => 'string', 'enum' => ['PRODUCT', 'ORDER', 'PAYMENT', 'EVENT', 'CALL', 'INVITE', 'SYSTEM']],
                'title' => ['type' => 'string', 'maxLength' => 4096],
                'description' => ['type' => 'string', 'maxLength' => 8192],
                'facts' => ['type' => 'array', 'maxItems' => 12, 'items' => ['$ref' => '#/components/schemas/CommunicationRichCardFact']],
            ],
        ),
        'CommunicationPollVote' => closedObject(
            ['option_names', 'option_hashes'],
            [
                'option_names' => ['type' => 'array', 'items' => ['type' => 'string']],
                'option_hashes' => ['type' => 'array', 'items' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$']],
            ],
        ),
        'CommunicationInteractiveResponse' => closedObject(
            [],
            ['text' => ['type' => 'string'], 'selected_id' => ['type' => 'string']],
        ),
        'CommunicationMessageContent' => [
            'type' => ['object', 'null'],
            'additionalProperties' => false,
            'properties' => [
                'text' => ['type' => 'string'],
                'caption' => ['type' => 'string'],
                'link_preview' => ['$ref' => '#/components/schemas/CommunicationMessageLinkPreview'],
                'location' => ['$ref' => '#/components/schemas/CommunicationMessageLocation'],
                'contacts' => ['type' => 'array', 'maxItems' => 50, 'items' => ['$ref' => '#/components/schemas/CommunicationSharedContact']],
                'poll' => ['$ref' => '#/components/schemas/CommunicationMessagePoll'],
                'event' => ['$ref' => '#/components/schemas/CommunicationMessageEvent'],
                'interactive' => ['$ref' => '#/components/schemas/CommunicationMessageInteractive'],
                'rich_card' => ['$ref' => '#/components/schemas/CommunicationRichCard'],
                'ptt' => ['type' => 'boolean'],
                'gif' => ['type' => 'boolean'],
                'animated' => ['type' => 'boolean'],
                'duration_seconds' => ['type' => 'integer', 'minimum' => 0],
                'reactions' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 32]],
                'poll_votes' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CommunicationPollVote']],
                'interactive_response' => ['$ref' => '#/components/schemas/CommunicationInteractiveResponse'],
                'content_present' => ['type' => 'boolean'],
                'variants' => ['type' => 'array', 'maxItems' => 16, 'items' => ['type' => 'string']],
            ],
        ],
        'CommunicationGatewayAction' => closedObject(
            ['command_id', 'action', 'status'],
            [
                'command_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$'],
                'action' => ['type' => 'string', 'enum' => ['EDIT', 'REACTION', 'REVOKE']],
                'status' => ['type' => 'string', 'enum' => ['PENDING', 'SUCCEEDED', 'FAILED']],
                'requested_at' => ['type' => 'string', 'format' => 'date-time'],
                'completed_at' => ['type' => 'string', 'format' => 'date-time'],
                'error_code' => ['type' => 'string', 'enum' => ['ACTION_REJECTED', 'ACTION_RETRY_EXHAUSTED', 'ACTION_OUTCOME_UNKNOWN']],
            ],
        ),
        'CommunicationMessageMetadata' => closedObject(
            [],
            [
                'edited_at' => ['type' => 'string', 'format' => 'date-time'],
                'revoked' => ['type' => 'boolean'],
                'history' => ['type' => 'boolean'],
                'ephemeral' => ['type' => 'boolean'],
                'view_once' => ['type' => 'boolean'],
                'media_state' => ['type' => 'string', 'enum' => ['READY', 'UNAVAILABLE', 'RETRY_AVAILABLE', 'REQUESTED', 'FAILED']],
                'media_error_code' => ['type' => 'string'],
                'gateway_actions' => ['type' => 'array', 'maxItems' => 10, 'items' => ['$ref' => '#/components/schemas/CommunicationGatewayAction']],
            ],
        ),
        'CommunicationMessageAttachment' => closedObject(
            ['id', 'filename', 'mime_type', 'size_bytes', 'sha256', 'download_url', 'preview_url', 'purged_at'],
            [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'filename' => ['type' => 'string'],
                'mime_type' => ['type' => 'string'],
                'size_bytes' => ['type' => 'integer', 'minimum' => 0],
                'sha256' => ['type' => 'string', 'pattern' => '^[a-f0-9]{64}$'],
                'download_url' => ['type' => 'string', 'format' => 'uri-reference'],
                'preview_url' => nullableString(),
                'purged_at' => nullableString('date-time'),
            ],
        ),
        'CommunicationMessage' => closedObject(
            [
                'id', 'conversation_id', 'direction', 'kind', 'provider_type', 'source', 'status',
                'body', 'content', 'availability', 'reply_to_message_id', 'author_membership_id',
                'occurred_at', 'sent_at', 'delivered_at', 'read_at', 'played_at', 'revoked_at',
                'metadata', 'attachments',
            ],
            [
                'id' => ['type' => 'integer'],
                'conversation_id' => ['type' => 'integer'],
                'direction' => ['type' => 'string', 'enum' => ['INBOUND', 'OUTBOUND', 'INTERNAL']],
                'kind' => ['type' => 'string', 'enum' => array_column(MessageKind::cases(), 'value')],
                'provider_type' => ['type' => ['string', 'null']],
                'source' => ['type' => 'string', 'enum' => array_column(MessageSource::cases(), 'value')],
                'status' => ['type' => 'string', 'enum' => array_column(MessageStatus::cases(), 'value')],
                'body' => nullableString(),
                'content' => ['$ref' => '#/components/schemas/CommunicationMessageContent'],
                'availability' => ['$ref' => '#/components/schemas/CommunicationMessageAvailability'],
                'reply_to_message_id' => ['type' => ['integer', 'null']],
                'author_membership_id' => ['type' => ['integer', 'null']],
                'occurred_at' => nullableString('date-time'),
                'sent_at' => nullableString('date-time'),
                'delivered_at' => nullableString('date-time'),
                'read_at' => nullableString('date-time'),
                'played_at' => nullableString('date-time'),
                'revoked_at' => nullableString('date-time'),
                'metadata' => ['$ref' => '#/components/schemas/CommunicationMessageMetadata'],
                'attachments' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CommunicationMessageAttachment']],
            ],
        ),
        'CommunicationMessageResponse' => closedObject(
            ['data'],
            ['data' => ['$ref' => '#/components/schemas/CommunicationMessage']],
        ),
        'CommunicationSendMessageBody' => closedObject(
            [],
            [
                'body' => ['type' => 'string', 'maxLength' => 4096],
                'file' => ['type' => 'string', 'format' => 'binary'],
                'library_sticker_id' => [
                    'type' => 'string',
                    'pattern' => '^[A-Za-z0-9_-]{8,128}$',
                    'description' => 'Opaque KontiveHub sticker library ID. Resolves private WebP into the STICKER outbound path; does not mutate WhatsApp mobile favorites.',
                ],
                'kind' => ['type' => 'string', 'enum' => array_column(MessageKind::cases(), 'value')],
                'ptt' => ['type' => 'boolean'],
                'gif' => ['type' => 'boolean'],
                'ptv' => ['type' => 'boolean'],
                'view_once' => ['type' => 'boolean'],
                'link_preview' => ['$ref' => '#/components/schemas/CommunicationMessageLinkPreview'],
                'location' => ['$ref' => '#/components/schemas/CommunicationMessageLocation'],
                'contact' => ['$ref' => '#/components/schemas/CommunicationSharedContact'],
                'contacts' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 10, 'items' => ['$ref' => '#/components/schemas/CommunicationSharedContact']],
                'poll' => ['$ref' => '#/components/schemas/CommunicationMessagePoll'],
                'event' => ['$ref' => '#/components/schemas/CommunicationMessageEvent'],
                'interactive' => ['$ref' => '#/components/schemas/CommunicationMessageInteractive'],
                'reply_to_message_id' => ['type' => 'integer', 'minimum' => 1],
                'receipt_message_id' => ['type' => 'integer', 'minimum' => 1],
                'internal_note' => ['type' => 'boolean'],
                'idempotency_key' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$'],
            ],
        ),
        'CommunicationStickerLibraryItem' => closedObject(
            ['id', 'inbox_id', 'source', 'availability', 'available', 'device_favorite', 'app_favorite'],
            [
                'id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{8,128}$'],
                'inbox_id' => ['type' => 'integer', 'minimum' => 1],
                'source' => ['type' => 'string', 'enum' => ['DEVICE_RECENT', 'DEVICE_FAVORITE', 'DEVICE_MESSAGE', 'LOCAL_IMPORT']],
                'availability' => ['type' => 'string'],
                'available' => ['type' => 'boolean'],
                'unavailable_reason' => ['type' => 'string', 'nullable' => true],
                'device_favorite' => [
                    'type' => 'boolean',
                    'description' => 'Observed WhatsApp device favorite; distinct from KontiveHub app_favorite.',
                ],
                'app_favorite' => [
                    'type' => 'boolean',
                    'description' => 'KontiveHub operator preference only; does not mutate WhatsApp mobile favorites.',
                ],
                'mime_type' => ['type' => 'string', 'nullable' => true],
                'size_bytes' => ['type' => 'integer', 'nullable' => true],
                'width' => ['type' => 'integer', 'nullable' => true],
                'height' => ['type' => 'integer', 'nullable' => true],
                'animated' => ['type' => 'boolean', 'nullable' => true],
                'preview_url' => ['type' => 'string', 'nullable' => true],
                'last_observed_at' => nullableString('date-time'),
            ],
        ),
        'CommunicationStickerLibraryCollection' => closedObject(
            ['data', 'meta'],
            [
                'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CommunicationStickerLibraryItem']],
                'meta' => closedObject(
                    ['sync_status'],
                    [
                        'current_page' => ['type' => 'integer'],
                        'last_page' => ['type' => 'integer'],
                        'per_page' => ['type' => 'integer'],
                        'total' => ['type' => 'integer'],
                        'sync_status' => ['type' => 'string', 'enum' => ['partial', 'not_observed', 'syncing', 'failed']],
                        'sync_reason' => ['type' => 'string', 'nullable' => true],
                        'last_observed_at' => nullableString('date-time'),
                    ],
                ),
            ],
        ),
        'CommunicationStickerLibraryItemResponse' => closedObject(
            ['data'],
            ['data' => ['$ref' => '#/components/schemas/CommunicationStickerLibraryItem']],
        ),
        'CommunicationStickerLibraryStatus' => closedObject(
            ['status', 'reason_code', 'complete', 'capability'],
            [
                'status' => ['type' => 'string', 'enum' => ['partial', 'not_observed', 'syncing', 'failed']],
                'reason_code' => ['type' => 'string'],
                'last_observed_at' => nullableString('date-time'),
                'complete' => ['type' => 'boolean', 'const' => false],
                'capability' => ['type' => 'object', 'additionalProperties' => true],
            ],
        ),
        'CommunicationStickerLibraryStatusResponse' => closedObject(
            ['data'],
            ['data' => ['$ref' => '#/components/schemas/CommunicationStickerLibraryStatus']],
        ),
        'CommunicationMessageBatchItemBody' => closedObject(
            ['file', 'kind'],
            [
                'file' => ['type' => 'string', 'format' => 'binary'],
                'kind' => ['type' => 'string', 'enum' => ['IMAGE', 'VIDEO', 'DOCUMENT']],
                'caption' => ['type' => 'string', 'maxLength' => 4096],
                'gif' => ['type' => 'boolean'],
                'ptv' => ['type' => 'boolean'],
                'view_once' => ['type' => 'boolean'],
            ],
        ),
        'CommunicationMessageBatchBody' => closedObject(
            ['client_batch_id', 'items'],
            [
                'client_batch_id' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$'],
                'items' => [
                    'type' => 'array',
                    'minItems' => 2,
                    'maxItems' => 10,
                    'items' => ['$ref' => '#/components/schemas/CommunicationMessageBatchItemBody'],
                ],
            ],
        ),
        'CommunicationMessageBatch' => closedObject(
            ['id', 'client_batch_id', 'conversation_id', 'status', 'item_count', 'messages', 'created_at'],
            [
                'id' => ['type' => 'integer'],
                'client_batch_id' => ['type' => 'string'],
                'conversation_id' => ['type' => 'integer'],
                'status' => ['type' => 'string', 'enum' => ['QUEUED']],
                'item_count' => ['type' => 'integer', 'minimum' => 2, 'maximum' => 10],
                'messages' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/CommunicationMessage']],
                'created_at' => nullableString('date-time'),
            ],
        ),
        'CommunicationMessageBatchResponse' => closedObject(
            ['data'],
            ['data' => ['$ref' => '#/components/schemas/CommunicationMessageBatch']],
        ),
        'CommunicationGifSearchResult' => closedObject(
            ['id', 'title', 'preview_path', 'asset_path', 'asset_token'],
            [
                'id' => ['type' => 'string', 'maxLength' => 128],
                'title' => ['type' => 'string', 'maxLength' => 200],
                'preview_path' => ['type' => 'string', 'pattern' => '^/api/v1/communication/gifs/[A-Za-z0-9]{40}/preview$'],
                'asset_path' => ['type' => 'string', 'pattern' => '^/api/v1/communication/gifs/[A-Za-z0-9]{40}/asset$'],
                'asset_token' => ['type' => 'string', 'pattern' => '^[A-Za-z0-9]{40}$'],
            ],
        ),
        'CommunicationGifSearchResponse' => closedObject(
            ['data'],
            [
                'data' => [
                    'type' => 'array',
                    'maxItems' => 25,
                    'items' => ['$ref' => '#/components/schemas/CommunicationGifSearchResult'],
                ],
            ],
        ),
        'CommunicationSaveSharedContactBody' => closedObject(
            ['phone_index'],
            ['phone_index' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9]],
        ),
        'CommunicationSaveSharedContactData' => closedObject(
            ['outcome', 'contact'],
            [
                'outcome' => ['type' => 'string', 'enum' => ['created', 'existing']],
                'contact' => ['$ref' => '#/components/schemas/CommunicationContact'],
            ],
        ),
        'CommunicationSaveSharedContactResponse' => closedObject(
            ['data'],
            ['data' => ['$ref' => '#/components/schemas/CommunicationSaveSharedContactData']],
        ),
        'CommunicationMessageCollection' => closedObject(
            ['data', 'meta'],
            [
                'data' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/CommunicationMessage'],
                ],
                'meta' => ['type' => 'object', 'additionalProperties' => true],
            ],
        ),
        'CommunicationConversation' => [
            'type' => 'object',
            'additionalProperties' => true,
            'properties' => [
                'contact' => nullableRef('#/components/schemas/CommunicationConversationContact'),
                'last_message' => nullableRef('#/components/schemas/CommunicationMessage'),
                'messages' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/CommunicationMessage'],
                ],
            ],
        ],
        'CommunicationConversationResponse' => closedObject(
            ['data'],
            ['data' => ['$ref' => '#/components/schemas/CommunicationConversation']],
        ),
        'CommunicationConversationCollection' => closedObject(
            ['data', 'meta'],
            [
                'data' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/CommunicationConversation'],
                ],
                'meta' => ['$ref' => '#/components/schemas/CommunicationConversationPaginationMeta'],
            ],
        ),
        'StartCommunicationConversationBody' => [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['contact_id', 'identity_id', 'inbox_id'],
            'anyOf' => [
                ['required' => ['body']],
                ['required' => ['file']],
            ],
            'properties' => [
                'contact_id' => ['type' => 'integer', 'minimum' => 1],
                'identity_id' => ['type' => 'integer', 'minimum' => 1],
                'inbox_id' => ['type' => 'integer', 'minimum' => 1],
                'body' => ['type' => 'string', 'maxLength' => 4096],
                'kind' => [
                    'type' => 'string',
                    'enum' => ['TEXT', 'IMAGE', 'AUDIO', 'VIDEO', 'DOCUMENT', 'STICKER'],
                ],
                'file' => ['type' => 'string', 'format' => 'binary'],
                'ptt' => ['type' => 'boolean'],
            ],
        ],
        'StartCommunicationConversationData' => closedObject(
            ['conversation', 'message', 'reused_conversation'],
            [
                'conversation' => ['$ref' => '#/components/schemas/CommunicationConversation'],
                'message' => ['$ref' => '#/components/schemas/CommunicationMessage'],
                'reused_conversation' => ['type' => 'boolean'],
            ],
        ),
        'StartCommunicationConversationResponse' => closedObject(
            ['data'],
            ['data' => ['$ref' => '#/components/schemas/StartCommunicationConversationData']],
        ),
        'CommunicationSharedContentCategory' => [
            'type' => 'string',
            'enum' => ['media', 'links', 'documents'],
        ],
        'CommunicationSharedContentAttachment' => closedObject(
            ['id', 'filename', 'mime_type', 'size_bytes', 'preview_url', 'download_url'],
            [
                'id' => ['type' => 'integer', 'minimum' => 1],
                'filename' => ['type' => 'string'],
                'mime_type' => ['type' => 'string'],
                'size_bytes' => ['type' => 'integer', 'minimum' => 0],
                'preview_url' => nullableString(),
                'download_url' => nullableString(),
            ],
        ),
        'CommunicationSharedContentLink' => closedObject(
            ['url', 'title', 'description'],
            [
                'url' => ['type' => 'string', 'format' => 'uri', 'pattern' => '^https?://'],
                'title' => nullableString(),
                'description' => nullableString(),
            ],
        ),
        'CommunicationSharedContentItem' => closedObject(
            [
                'id', 'type', 'category', 'conversation_id', 'message_id',
                'occurred_at', 'attachment', 'link',
            ],
            [
                'id' => ['type' => 'string'],
                'type' => ['type' => 'string', 'enum' => ['attachment', 'link']],
                'category' => ['$ref' => '#/components/schemas/CommunicationSharedContentCategory'],
                'conversation_id' => ['type' => 'integer', 'minimum' => 1],
                'message_id' => ['type' => 'integer', 'minimum' => 1],
                'occurred_at' => nullableString('date-time'),
                'attachment' => nullableRef('#/components/schemas/CommunicationSharedContentAttachment'),
                'link' => nullableRef('#/components/schemas/CommunicationSharedContentLink'),
            ],
        ),
        'CommunicationSharedContentMeta' => closedObject(
            [
                'next_cursor', 'snapshot_through_message_id',
                'snapshot_through_attachment_id', 'limit',
            ],
            [
                'next_cursor' => nullableString(),
                'snapshot_through_message_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'snapshot_through_attachment_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            ],
        ),
        'CommunicationSharedContentCollection' => closedObject(
            ['data', 'meta'],
            [
                'data' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/CommunicationSharedContentItem'],
                ],
                'meta' => ['$ref' => '#/components/schemas/CommunicationSharedContentMeta'],
            ],
        ),
        'CommunicationConversationInitiationCapability' => closedObject(
            ['enabled', 'reason', 'requires_permission'],
            [
                'enabled' => ['type' => 'boolean'],
                'reason' => [
                    'type' => ['string', 'null'],
                    'enum' => [
                        'rollout_disabled', 'kill_switch_active', 'tenant_not_allowlisted',
                        'gateway_unavailable', 'tenant_disabled', 'permission_denied',
                        'inbox_unavailable', null,
                    ],
                ],
                'requires_permission' => ['type' => 'string', 'const' => 'communication.reply'],
            ],
        ),
        'CommunicationOutboundCapabilities' => [
            'type' => 'object',
            'additionalProperties' => true,
            'required' => [
                'enabled', 'requires_permission', 'kinds', 'max_media_bytes',
                'conversation_initiation',
            ],
            'properties' => [
                'enabled' => ['type' => 'boolean'],
                'requires_permission' => ['type' => 'string', 'const' => 'communication.reply'],
                'kinds' => ['type' => 'object', 'additionalProperties' => true],
                'max_media_bytes' => ['type' => 'integer', 'minimum' => 1],
                'conversation_initiation' => ['$ref' => '#/components/schemas/CommunicationConversationInitiationCapability'],
            ],
        ],
        'CommunicationOutboundCapabilitiesResponse' => closedObject(
            ['data'],
            ['data' => ['$ref' => '#/components/schemas/CommunicationOutboundCapabilities']],
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
        'ConversationSavedViewPayload' => closedObject(
            ['status', 'sort_by'],
            [
                'status' => [
                    'type' => 'string',
                    'enum' => ['ALL', ...array_column(ConversationStatus::cases(), 'value')],
                ],
                'sort_by' => [
                    'type' => 'string',
                    'enum' => ConversationListSort::values(),
                ],
                'inbox_id' => ['type' => 'integer', 'minimum' => 1],
                'assignee_membership_id' => ['type' => 'integer', 'minimum' => 1],
                'work_department_id' => ['type' => 'integer', 'minimum' => 1],
                'label_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'maxItems' => 50,
                    'uniqueItems' => true,
                ],
                'unread' => ['type' => 'boolean'],
                'unassigned' => ['type' => 'boolean'],
            ],
        ),
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
                    'ConversationSavedViewPayload',
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

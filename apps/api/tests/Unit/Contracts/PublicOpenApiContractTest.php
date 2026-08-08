<?php

namespace Tests\Unit\Contracts;

use App\Enums\Communication\ConversationStatus;
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
            '/\b(?:office_id|offices|deprecated|a1)\b/i',
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
        $this->assertContains(
            'communication.conversations',
            $schemas['SavedListSurface']['enum'],
        );
        $this->assertFalse($schemas['ConversationSavedViewPayload']['additionalProperties']);
        $this->assertSame(
            ['status', 'sort_by'],
            $schemas['ConversationSavedViewPayload']['required'],
        );
        $this->assertSame(
            ['ALL', ...array_column(ConversationStatus::cases(), 'value')],
            $schemas['ConversationSavedViewPayload']['properties']['status']['enum'],
        );
        $this->assertContains(
            '#/components/schemas/ConversationSavedViewPayload',
            array_column($schemas['SavedListFilterPayload']['oneOf'], '$ref'),
        );
    }

    public function test_communication_contact_phone_contract_is_machine_readable(): void
    {
        $document = $this->document();
        $schemas = $document['components']['schemas'];

        $this->assertSame(
            '^\\+[1-9]\\d{7,14}$',
            $schemas['CommunicationContactIdentity']['properties']['phone']['pattern'],
        );
        $this->assertSame(
            'alias',
            $schemas['CommunicationConversationContact']['properties']['address']['x-kontivehub-lifecycle'],
        );
        $this->assertSame(
            '#/components/schemas/CommunicationContactSearchBody',
            $document['paths']['/api/v1/communication/contacts/search']['post']['requestBody']['content']['application/json']['schema']['$ref'],
        );
        $this->assertSame(
            '#/components/schemas/CommunicationContactCollection',
            $document['paths']['/api/v1/communication/contacts']['get']['responses']['200']['content']['application/json']['schema']['$ref'],
        );
        $this->assertContains(
            'contact_id',
            array_column(
                $document['paths']['/api/v1/communication/conversations']['get']['parameters'],
                'name',
            ),
        );
    }

    public function test_communication_contact_inbox_projection_contract_is_additive(): void
    {
        $document = $this->document();
        $schema = $document['components']['schemas']['CommunicationContact'];
        $search = $document['components']['schemas']['CommunicationContactSearchBody'];

        foreach ([
            'display_name',
            'display_name_source',
            'display_name_state',
            'display_name_inbox_id',
            'profile_picture_inbox_id',
        ] as $property) {
            $this->assertArrayHasKey($property, $schema['properties']);
            $this->assertNotContains($property, $schema['required']);
        }
        $this->assertSame(
            ['CURATED', 'OBSERVED', 'FALLBACK', null],
            $schema['properties']['display_name_state']['enum'],
        );
        $this->assertSame(1, $search['properties']['inbox_id']['minimum']);

        $listParameters = collect(
            $document['paths']['/api/v1/communication/contacts']['get']['parameters'],
        )->keyBy('name');
        $detailParameters = collect(
            $document['paths']['/api/v1/communication/contacts/{contact}']['get']['parameters'],
        )->keyBy('name');
        $patchParameters = collect(
            $document['paths']['/api/v1/communication/contacts/{contact}']['patch']['parameters'],
        )->keyBy('name');

        $this->assertSame('integer', $listParameters['inbox_id']['schema']['type']);
        $this->assertSame(1, $detailParameters['inbox_id']['schema']['minimum']);
        $this->assertFalse($listParameters['inbox_id']['required']);
        $this->assertFalse($patchParameters->has('inbox_id'));
    }

    public function test_communication_profile_picture_contract_is_additive_and_private(): void
    {
        $document = $this->document();
        $schemas = $document['components']['schemas'];

        foreach (['CommunicationContact', 'CommunicationConversationContact'] as $schema) {
            $this->assertSame(
                ['string', 'null'],
                $schemas[$schema]['properties']['profile_picture_url']['type'],
            );
            $this->assertContains('profile_picture_url', $schemas[$schema]['required']);
            $this->assertContains('profile_picture_state', $schemas[$schema]['required']);
            $this->assertSame(
                ['UNKNOWN', 'PENDING', 'READY', 'UNAVAILABLE', 'FAILED'],
                $schemas[$schema]['properties']['profile_picture_state']['enum'],
            );
        }

        $path = '/api/v1/communication/profile-pictures/{profile}/{version}';
        $this->assertArrayHasKey($path, $document['paths']);
        $operation = $document['paths'][$path]['get'];
        $this->assertSame([['sanctumCookie' => []]], $operation['security']);
        $this->assertContains('If-None-Match', array_column($operation['parameters'], 'name'));
        $this->assertSame(
            'private, no-cache, must-revalidate',
            $operation['responses']['200']['headers']['Cache-Control']['schema']['const'],
        );
        $this->assertSame(
            'binary',
            $operation['responses']['200']['content']['image/jpeg']['schema']['format'],
        );

        $json = json_encode($operation, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('picture_id', $json);
        $this->assertStringNotContainsString('profile_picture_object_id', $json);
    }

    public function test_communication_conversation_snapshot_contract_is_additive(): void
    {
        $document = $this->document();
        $operation = $document['paths']['/api/v1/communication/conversations']['get'];
        $parameters = collect($operation['parameters'])->keyBy('name');

        $this->assertSame('boolean', $parameters['snapshot']['schema']['type']);
        $this->assertSame('string', $parameters['snapshot_token']['schema']['type']);
        foreach (['410', '422', '503'] as $status) {
            $this->assertSame(
                '#/components/schemas/JsonResponse',
                $operation['responses'][$status]['content']['application/json']['schema']['$ref'],
            );
        }

        $meta = $document['components']['schemas']['CommunicationConversationPaginationMeta'];
        $this->assertFalse($meta['additionalProperties']);
        $this->assertSame(
            ['current_page', 'last_page', 'total'],
            $meta['required'],
        );
        $this->assertSame('date-time', $meta['properties']['snapshot_expires_at']['format']);
        $this->assertArrayHasKey('snapshot_token', $meta['properties']);
        $this->assertSame(
            '#/components/schemas/CommunicationConversationPaginationMeta',
            $document['components']['schemas']['CommunicationConversationCollection']['properties']['meta']['$ref'],
        );
        $this->assertSame(
            '#/components/schemas/CommunicationPaginationMeta',
            $document['components']['schemas']['CommunicationContactCollection']['properties']['meta']['$ref'],
        );
    }

    public function test_communication_message_availability_is_additive_and_allowlisted(): void
    {
        $document = $this->document();
        $schemas = $document['components']['schemas'];

        $this->assertSame([
            'AVAILABLE',
            'UNSUPPORTED',
            'MEDIA_RETRY_AVAILABLE',
            'MEDIA_REQUESTED',
            'MEDIA_FAILED',
            'UNAVAILABLE',
        ], $schemas['CommunicationMessageAvailabilityState']['enum']);
        $this->assertSame(
            '#/components/schemas/CommunicationMessageAvailability',
            $schemas['CommunicationMessage']['properties']['availability']['$ref'],
        );
        $this->assertContains('availability', $schemas['CommunicationMessage']['required']);
        $this->assertSame(
            '#/components/schemas/CommunicationMessageCollection',
            $document['paths']['/api/v1/communication/conversations/{conversation}/messages']['get']['responses']['200']['content']['application/json']['schema']['$ref'],
        );
    }

    public function test_communication_initiation_shared_content_and_message_anchor_are_explicit(): void
    {
        $document = $this->document();
        $schemas = $document['components']['schemas'];
        $start = $document['paths']['/api/v1/communication/conversations']['post'];

        $idempotency = collect($start['parameters'])->firstWhere('name', 'Idempotency-Key');
        $this->assertTrue($idempotency['required']);
        $this->assertSame(
            '#/components/schemas/StartCommunicationConversationBody',
            $start['requestBody']['content']['multipart/form-data']['schema']['$ref'],
        );
        $this->assertSame(
            '#/components/schemas/StartCommunicationConversationResponse',
            $start['responses']['202']['content']['application/json']['schema']['$ref'],
        );
        $this->assertArrayHasKey('200', $start['responses']);
        $this->assertArrayHasKey('409', $start['responses']);

        foreach ([
            '/api/v1/communication/contacts/{contact}/shared-content',
            '/api/v1/communication/conversations/{conversation}/shared-content',
        ] as $path) {
            $operation = $document['paths'][$path]['get'];
            $this->assertContains('category', array_column($operation['parameters'], 'name'));
            $this->assertContains('cursor', array_column($operation['parameters'], 'name'));
            $this->assertSame(
                '#/components/schemas/CommunicationSharedContentCollection',
                $operation['responses']['200']['content']['application/json']['schema']['$ref'],
            );
            $this->assertSame(
                'no-cache',
                $operation['responses']['200']['headers']['Pragma']['schema']['const'],
            );
        }
        $this->assertContains(
            'inbox_id',
            array_column(
                $document['paths']['/api/v1/communication/contacts/{contact}/shared-content']['get']['parameters'],
                'name',
            ),
        );
        $this->assertNotContains(
            'inbox_id',
            array_column(
                $document['paths']['/api/v1/communication/conversations/{conversation}/shared-content']['get']['parameters'],
                'name',
            ),
        );

        $messageParameters = $document['paths']['/api/v1/communication/conversations/{conversation}/messages']['get']['parameters'];
        $this->assertContains('message', collect($messageParameters)->firstWhere('name', 'anchor')['schema']['enum']);
        $this->assertNotNull(collect($messageParameters)->firstWhere('name', 'message_id'));
        $this->assertSame(
            '#/components/schemas/CommunicationConversationInitiationCapability',
            $schemas['CommunicationOutboundCapabilities']['properties']['conversation_initiation']['$ref'],
        );
        $this->assertFalse($schemas['CommunicationSharedContentItem']['additionalProperties']);
    }

    public function test_bulk_operations_preferences_and_shared_content_cache_contracts_are_explicit(): void
    {
        $document = $this->document();
        foreach ([
            '/api/v1/communication/conversation-bulk-operations' => 'post',
            '/api/v1/communication/conversation-bulk-operations/{operation}' => 'get',
            '/api/v1/communication/conversation-bulk-operations/{operation}/items' => 'get',
            '/api/v1/communication/conversation-list-preferences' => 'get',
        ] as $path => $method) {
            $responses = $document['paths'][$path][$method]['responses'] ?? [];
            $this->assertTrue(isset($responses['200']) || isset($responses['202']));
        }
        $post = $document['paths']['/api/v1/communication/conversation-bulk-operations']['post'];
        $this->assertContains('Idempotency-Key', array_column($post['parameters'], 'name'));
        $this->assertArrayHasKey('409', $post['responses']);
        $this->assertArrayHasKey('422', $post['responses']);
        $items = $document['paths']['/api/v1/communication/conversation-bulk-operations/{operation}/items']['get'];
        $this->assertEqualsCanonicalizing(['status', 'page', 'per_page'], array_slice(array_column($items['parameters'], 'name'), -3));
        $this->assertSame('private, no-store, max-age=0', $document['paths']['/api/v1/communication/conversations/{conversation}/shared-content']['get']['responses']['200']['headers']['Cache-Control']['schema']['const']);
    }

    public function test_communication_gif_proxy_contract_is_explicit_private_and_allowlisted(): void
    {
        $document = $this->document();
        $schemas = $document['components']['schemas'];
        $search = $document['paths']['/api/v1/communication/gifs/search']['get'];
        $parameters = collect($search['parameters'])->keyBy('name');

        $this->assertTrue($parameters['inbox_id']['required']);
        $this->assertSame(2, $parameters['q']['schema']['minLength']);
        $this->assertSame(25, $parameters['limit']['schema']['maximum']);
        $this->assertSame(
            '#/components/schemas/CommunicationGifSearchResponse',
            $search['responses']['200']['content']['application/json']['schema']['$ref'],
        );
        foreach (['403', '422', '503'] as $status) {
            $this->assertSame(
                '#/components/schemas/JsonResponse',
                $search['responses'][$status]['content']['application/json']['schema']['$ref'],
            );
        }

        $result = $schemas['CommunicationGifSearchResult'];
        $this->assertFalse($result['additionalProperties']);
        $this->assertSame(
            '^/api/v1/communication/gifs/[A-Za-z0-9]{40}/preview$',
            $result['properties']['preview_path']['pattern'],
        );
        $this->assertSame(
            '^/api/v1/communication/gifs/[A-Za-z0-9]{40}/asset$',
            $result['properties']['asset_path']['pattern'],
        );
        $this->assertArrayNotHasKey('media_url', $result['properties']);

        $preview = $document['paths']['/api/v1/communication/gifs/{token}/preview']['get'];
        $this->assertSame('^[A-Za-z0-9]{40}$', $preview['parameters'][0]['schema']['pattern']);
        $this->assertSame(
            '(?=.*\\bprivate\\b)(?=.*\\bno-store\\b)',
            $preview['responses']['200']['headers']['Cache-Control']['schema']['pattern'],
        );
        $this->assertSame(
            'binary',
            $preview['responses']['200']['content']['image/gif']['schema']['format'],
        );

        $asset = $document['paths']['/api/v1/communication/gifs/{token}/asset']['get'];
        $this->assertSame('^[A-Za-z0-9]{40}$', $asset['parameters'][0]['schema']['pattern']);
        $this->assertSame(
            '(?=.*\\bprivate\\b)(?=.*\\bno-store\\b)',
            $asset['responses']['200']['headers']['Cache-Control']['schema']['pattern'],
        );
        $this->assertSame(
            'binary',
            $asset['responses']['200']['content']['video/mp4']['schema']['format'],
        );
        $this->assertSame(
            '#/components/schemas/JsonResponse',
            $asset['responses']['503']['content']['application/json']['schema']['$ref'],
        );
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

<?php

namespace Tests\Unit\Communication;

use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\GatewayEventType;
use App\Enums\Communication\GatewayQueryType;
use App\Enums\Communication\MessageKind;
use RuntimeException;
use Tests\TestCase;

class GatewayContractOpenApiTest extends TestCase
{
    public function test_contract_identifies_wazync_without_renaming_the_generic_gateway_boundary(): void
    {
        $yaml = $this->yaml();

        $this->assertStringContainsString('title: Contrato interno KontiveHub ↔ Wazync', $yaml);
        $this->assertStringContainsString('url: http://wazync:8080', $yaml);
        $this->assertStringContainsString('GatewayCommand:', $yaml);
        $this->assertStringContainsString('GatewayEvent:', $yaml);
        $this->assertStringContainsString('/internal/v1/commands:', $yaml);
        $this->assertStringContainsString('X-Communication-Signature', $yaml);
    }

    public function test_openapi_enums_match_php_contract_exactly(): void
    {
        $this->assertEqualsCanonicalizing(
            array_column(GatewayCommandType::cases(), 'value'),
            $this->enumValues('GatewayCommandType'),
        );
        $this->assertEqualsCanonicalizing(
            array_column(GatewayQueryType::cases(), 'value'),
            $this->enumValues('GatewayQueryType'),
        );
        $this->assertEqualsCanonicalizing(
            array_column(GatewayEventType::cases(), 'value'),
            $this->enumValues('GatewayEventType'),
        );
        $this->assertEqualsCanonicalizing(
            array_values(array_filter(
                array_column(MessageKind::cases(), 'value'),
                static fn (string $kind): bool => $kind !== MessageKind::Note->value,
            )),
            $this->enumValues('MessageKind'),
        );
    }

    public function test_every_command_and_query_type_is_bound_to_a_closed_payload_schema(): void
    {
        $commandMapping = $this->schemaBlock('CommandPayloadByType');
        foreach (GatewayCommandType::cases() as $type) {
            $this->assertStringContainsString($type->value, $commandMapping);
        }

        $queryMapping = $this->schemaBlock('QueryPayloadByType');
        foreach (GatewayQueryType::cases() as $type) {
            $this->assertStringContainsString($type->value, $queryMapping);
        }

        foreach ([
            'EmptyPayload',
            'SessionProvisionPayload',
            'PairPhonePayload',
            'TextMessageSendPayload',
            'MediaMessageSendPayload',
            'MessageTargetPayload',
            'MessageEditPayload',
            'MessageReactionPayload',
            'PollVotePayload',
            'MessageMarkPayload',
            'MediaRetryPayload',
            'UsersQueryPayload',
            'ProfilePictureQueryPayload',
            'ContactQRQueryPayload',
            'LinkQueryPayload',
            'QueryResult',
            'GatewayEvent',
            'SourceIdentity',
        ] as $schema) {
            $this->assertStringContainsString(
                'additionalProperties: false',
                $this->schemaBlock($schema),
                "Schema {$schema} precisa permanecer fechado.",
            );
        }
    }

    public function test_every_message_variant_requires_kind(): void
    {
        foreach ([
            'TextMessageSendPayload',
            'MediaMessageSendPayload',
            'LocationMessageSendPayload',
            'ContactMessageSendPayload',
            'PollMessageSendPayload',
            'InteractiveMessageSendPayload',
        ] as $schema) {
            $this->assertMatchesRegularExpression('/required: \[[^\]]*kind[^\]]*\]/', $this->schemaBlock($schema));
        }

        $description = strtolower($this->yaml());
        $this->assertStringContainsString('todo `message_send` exige `kind` explícito', $description);
        $this->assertStringNotContainsString('payload sem tipagem', $description);
        $this->assertStringNotContainsString('tipo é inferido', $description);
    }

    public function test_query_endpoint_documents_all_hmac_headers_and_replay_before_provider(): void
    {
        $operation = $this->pathBlock('/internal/v1/queries');

        foreach (['KeyId', 'Timestamp', 'Nonce', 'Signature'] as $parameter) {
            $this->assertStringContainsString("#/components/parameters/{$parameter}", $operation);
        }
        $this->assertStringContainsString('não pode ser reutilizado', $operation);
        $this->assertStringContainsString('antes de qualquer chamada ao provider', $operation);

        $signature = $this->parameterBlock('Signature');
        foreach (['método HTTP', 'path escapado', 'timestamp decimal', 'nonce', 'SHA-256'] as $component) {
            $this->assertStringContainsString($component, $signature);
        }
    }

    public function test_contract_has_no_protocol_or_secret_property_escape_hatch(): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/^\s+(?:access_token|credentials|device_jid|direct_path|media_key|node|protobuf|qr_code|raw_event|raw_node|raw_proto|refresh_token|thumbnail_base64|token):/m',
            $this->yaml(),
        );
        $this->assertStringContainsString("pattern: '^\\+[1-9][0-9]{7,14}$'", $this->schemaBlock('E164'));
        $this->assertStringNotContainsString('@g.us', $this->schemaBlock('OneToOneAddress'));
        $this->assertStringNotContainsString('newsletter', strtolower($this->schemaBlock('OneToOneAddress')));
    }

    public function test_event_schemas_cover_normalized_history_actions_and_app_state_without_raw_escape_hatches(): void
    {
        $message = $this->schemaBlock('MessageReceivedEventPayload');
        foreach ([
            'direction:', 'history:', 'InboundMessageReference', 'provider_type:', 'family:',
            'contacts:', 'interactive:', 'content_present:', 'variants:',
        ] as $field) {
            $this->assertStringContainsString($field, $message);
        }
        $this->assertStringContainsString('PLAYED', $this->schemaBlock('MessageStatusEventPayload'));
        foreach (['generation:', 'attempt:', 'size_bytes:', 'sha256:', 'mime_type:', 'filename:'] as $field) {
            $this->assertStringContainsString($field, $this->schemaBlock('MediaRetryEventPayload'));
        }
        $this->assertStringContainsString('media_state:', $message);
        foreach (['protocolMessage', 'const: TEXT', 'const: UNSUPPORTED', 'content_present: {const: true}'] as $constraint) {
            $this->assertStringContainsString($constraint, $message);
        }
        $mediaInvariant = $this->schemaBlock('MessageReceivedMediaInvariant');
        $this->assertStringContainsString('oneOf:', $mediaInvariant);
        $this->assertStringContainsString('required: [spool_id, media_size_bytes, media_sha256, mime_type]', $mediaInvariant);
        $this->assertStringContainsString('media_state: {enum: [RETRY_AVAILABLE, REQUESTED, FAILED, UNAVAILABLE]}', $mediaInvariant);
        $mediaRetry = $this->schemaBlock('MediaRetryPayload');
        $this->assertStringContainsString('expected_direction:', $mediaRetry);
        $this->assertStringContainsString('oneOf:', $mediaRetry);
        $mediaRetryEvent = $this->schemaBlock('MediaRetryEventPayload');
        foreach (['MEDIA_RETRY_PROVIDER_ERROR', 'MEDIA_RETRY_DESCRIPTOR_EXPIRED', 'required: [error_code]'] as $constraint) {
            $this->assertStringContainsString($constraint, $mediaRetryEvent);
        }
        $this->assertStringContainsString('provider_message_id', $this->schemaBlock('InboundMessageReference'));
        $this->assertStringContainsString('source_identity:', $message);
        $sourceIdentity = $this->schemaBlock('SourceIdentity');
        foreach (['primary:', 'primary_kind:', 'alternate:', 'alternate_kind:', 'evidence:'] as $field) {
            $this->assertStringContainsString($field, $sourceIdentity);
        }
        foreach (['const: LID', 'const: PN', 'const: MESSAGE_SOURCE_ALT'] as $constraint) {
            $this->assertStringContainsString($constraint, $sourceIdentity);
        }
        $action = $this->schemaBlock('MessageActionEventPayload');
        $this->assertStringContainsString('history:', $action);
        $this->assertStringContainsString('source_identity:', $action);

        $chatState = $this->schemaBlock('ChatStateEventPayload');
        foreach (['DELETE_FOR_ME', 'CLEAR_CHAT', 'LABEL_CHAT', 'LABEL_MESSAGE', 'label_id:'] as $value) {
            $this->assertStringContainsString($value, $chatState);
        }

        $history = $this->schemaBlock('HistoryEventPayload');
        foreach (['sync_type:', 'chunk_order:', 'progress:', 'message_count:', 'rejected_count:', 'truncated:'] as $field) {
            $this->assertStringContainsString($field, $history);
        }
    }

    public function test_contact_profile_query_and_events_are_closed_and_source_aware(): void
    {
        $queryResult = $this->schemaBlock('QueryResult');
        $this->assertStringContainsString('ContactProfilesResult', $queryResult);

        $profile = $this->schemaBlock('ContactProfileResult');
        foreach ([
            'required: [user, found]',
            'address_book_first_name:',
            'address_book_full_name:',
            'push_name:',
            'business_name:',
            'observed_at:',
            'event_id:',
        ] as $field) {
            $this->assertStringContainsString($field, $profile);
        }
        $this->assertStringNotContainsString('verified_name:', $profile);
        $this->assertStringNotContainsString('address_book_name:', $profile);

        $event = $this->schemaBlock('ContactProfileEventPayload');
        foreach ([
            'ADDRESS_BOOK', 'PUSH', 'BUSINESS', 'PICTURE', 'ABOUT',
            'cleared_fields:', 'from_full_sync:', 'source_identity:',
        ] as $field) {
            $this->assertStringContainsString($field, $event);
        }
        $this->assertStringNotContainsString('picture_url:', $event);
    }

    /** @return list<string> */
    private function enumValues(string $schema): array
    {
        preg_match_all('/^        - ([A-Z][A-Z0-9_]*)$/m', $this->schemaBlock($schema), $matches);

        return $matches[1];
    }

    private function schemaBlock(string $name): string
    {
        return $this->indentedBlock("    {$name}:\n", 4);
    }

    private function parameterBlock(string $name): string
    {
        return $this->indentedBlock("    {$name}:\n", 4);
    }

    private function pathBlock(string $path): string
    {
        return $this->indentedBlock("  {$path}:\n", 2);
    }

    private function indentedBlock(string $marker, int $indent, ?string $source = null): string
    {
        $source ??= $this->yaml();
        $start = strpos($source, $marker);
        if ($start === false) {
            throw new RuntimeException("Bloco OpenAPI ausente: {$marker}");
        }
        $start += strlen($marker);
        $tail = substr($source, $start);
        $pattern = '/^'.str_repeat(' ', $indent).'[A-Za-z0-9_\/{}.-]+:\s*$/m';
        if (preg_match($pattern, $tail, $match, PREG_OFFSET_CAPTURE) === 1) {
            $tail = substr($tail, 0, $match[0][1]);
        }

        return $marker.$tail;
    }

    private function yaml(): string
    {
        $contents = file_get_contents(resource_path('contracts/wazync.openapi.yaml'));
        if (! is_string($contents)) {
            throw new RuntimeException('Contrato OpenAPI do gateway não pôde ser lido.');
        }

        return $contents;
    }
}

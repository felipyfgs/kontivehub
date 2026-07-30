<?php

namespace App\DTO\Communication;

use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\GatewayEventType;
use App\Enums\Communication\GatewayQueryType;
use App\Enums\Communication\MessageKind;
use BackedEnum;
use InvalidArgumentException;

final class GatewayContractPayload
{
    /** @var list<string> */
    private const TECHNICAL_MESSAGE_TYPES = [
        'protocolmessage',
        'reactionmessage',
        'pollupdatemessage',
        'editedmessage',
        'keepinchatmessage',
        'senderkeydistributionmessage',
    ];

    /** @var list<string> */
    private const MEDIA_RETRY_FAILURE_CODES = [
        'MEDIA_RETRY_STATE_MISSING',
        'MEDIA_RETRY_INVALID_REQUEST',
        'MEDIA_RETRY_REQUEST_FAILED',
        'MEDIA_RETRY_DESCRIPTOR_INVALID',
        'MEDIA_RETRY_DESCRIPTOR_EXPIRED',
        'MEDIA_RETRY_DECRYPT_FAILED',
        'MEDIA_RETRY_PROVIDER_ERROR',
        'MEDIA_RETRY_NOT_AVAILABLE',
        'MEDIA_RETRY_SPOOL_UNAVAILABLE',
        'MEDIA_RETRY_DOWNLOAD_FAILED',
        'MEDIA_RETRY_DIGEST_MISMATCH',
    ];

    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'access_token',
        'credentials',
        'device_jid',
        'device',
        'direct_path',
        'media_key',
        'jid',
        'node',
        'protobuf',
        'qr',
        'qr_code',
        'raw',
        'raw_event',
        'raw_node',
        'raw_proto',
        'refresh_token',
        'secret',
        'thumbnail_base64',
        'token',
    ];

    /** @var array<string, array{allowed: list<string>, required: list<string>}> */
    private const COMMAND_SHAPES = [
        'SESSION_PROVISION' => ['allowed' => ['desired_connected'], 'required' => []],
        'SESSION_PAIR' => ['allowed' => [], 'required' => []],
        'SESSION_PAIR_PHONE' => ['allowed' => ['phone', 'show_push_notification'], 'required' => ['phone']],
        'SESSION_PASSKEY_RESPOND' => ['allowed' => ['id', 'client_data_json', 'authenticator_data', 'signature'], 'required' => ['id', 'client_data_json', 'authenticator_data', 'signature']],
        'SESSION_PASSKEY_CONFIRM' => ['allowed' => ['id', 'confirm'], 'required' => ['id', 'confirm']],
        'SESSION_CONNECT' => ['allowed' => [], 'required' => []],
        'SESSION_DISCONNECT' => ['allowed' => [], 'required' => []],
        'SESSION_SET_PASSIVE' => ['allowed' => ['passive'], 'required' => ['passive']],
        'SESSION_LOGOUT' => ['allowed' => [], 'required' => []],
        'MESSAGE_SEND' => ['allowed' => ['to', 'kind', 'text', 'caption', 'reply_to', 'link_preview', 'media', 'location', 'contact', 'poll', 'interactive'], 'required' => ['to', 'kind']],
        'MESSAGE_EDIT' => ['allowed' => ['to', 'target_message_id', 'sender', 'text'], 'required' => ['to', 'target_message_id', 'text']],
        'MESSAGE_REVOKE' => ['allowed' => ['to', 'target_message_id', 'sender'], 'required' => ['to', 'target_message_id']],
        'MESSAGE_REACT' => ['allowed' => ['to', 'target_message_id', 'sender', 'emoji'], 'required' => ['to', 'target_message_id', 'emoji']],
        'POLL_VOTE' => ['allowed' => ['to', 'target_message_id', 'sender', 'option_names'], 'required' => ['to', 'target_message_id', 'option_names']],
        'MESSAGE_MARK' => ['allowed' => ['to', 'message_ids', 'receipt', 'sender', 'timestamp', 'protocol'], 'required' => ['to', 'message_ids', 'receipt']],
        'MESSAGE_REQUEST_UNAVAILABLE' => ['allowed' => ['to', 'target_message_id', 'sender'], 'required' => ['to', 'target_message_id']],
        'MEDIA_RETRY_REQUEST' => ['allowed' => ['to', 'target_message_id', 'sender', 'from_me', 'expected_direction'], 'required' => ['to', 'target_message_id']],
        'PRESENCE_SET' => ['allowed' => ['presence', 'force_active_delivery_receipts'], 'required' => ['presence']],
        'PRESENCE_SUBSCRIBE' => ['allowed' => ['to'], 'required' => ['to']],
        'CHAT_PRESENCE_SET' => ['allowed' => ['to', 'presence', 'media'], 'required' => ['to', 'presence']],
        'CHAT_DISAPPEARING_SET' => ['allowed' => ['to', 'timer_seconds'], 'required' => ['to', 'timer_seconds']],
        'CHAT_STATE_UPDATE' => ['allowed' => ['to', 'action', 'value', 'target_message_id', 'sender', 'timestamp', 'duration_seconds', 'delete_media', 'from_me'], 'required' => ['action']],
        'BLOCKLIST_UPDATE' => ['allowed' => ['to', 'action'], 'required' => ['to', 'action']],
        'PRIVACY_UPDATE' => ['allowed' => ['name', 'value'], 'required' => ['name', 'value']],
        'DEFAULT_DISAPPEARING_SET' => ['allowed' => ['timer_seconds'], 'required' => ['timer_seconds']],
        'HISTORY_SYNC_REQUEST' => ['allowed' => ['to', 'last_message_id', 'last_message_from', 'last_message_timestamp', 'last_message_from_me', 'count'], 'required' => ['to', 'last_message_id', 'last_message_from', 'last_message_timestamp', 'last_message_from_me', 'count']],
    ];

    /** @var array<string, array{allowed: list<string>, required: list<string>}> */
    private const QUERY_SHAPES = [
        'USER_CHECK' => ['allowed' => ['users'], 'required' => ['users']],
        'USER_INFO' => ['allowed' => ['users'], 'required' => ['users']],
        'BUSINESS_PROFILE' => ['allowed' => ['users'], 'required' => ['users']],
        'PROFILE_PICTURE' => ['allowed' => ['user', 'preview'], 'required' => ['user']],
        'CONTACT_QR_LINK' => ['allowed' => ['revoke'], 'required' => []],
        'CONTACT_QR_RESOLVE' => ['allowed' => ['link'], 'required' => ['link']],
        'BUSINESS_LINK_RESOLVE' => ['allowed' => ['link'], 'required' => ['link']],
        'BLOCKLIST' => ['allowed' => [], 'required' => []],
        'PRIVACY_SETTINGS' => ['allowed' => [], 'required' => []],
        'CONTACT_PROFILES' => ['allowed' => ['users'], 'required' => ['users']],
    ];

    /** @param array<string, mixed> $payload */
    public static function assertCommand(GatewayCommandType $type, array $payload): void
    {
        self::assertShape($payload, self::COMMAND_SHAPES[$type->value], 'comando '.$type->value);
        if ($type === GatewayCommandType::SendMessage) {
            self::assertOutboundMessage($payload);
        }
        if ($type === GatewayCommandType::UpdateChatState) {
            $action = strtoupper(trim((string) ($payload['action'] ?? '')));
            if ($action === 'MARK_CLEAN' && (int) ($payload['timestamp'] ?? 0) <= 0) {
                throw new InvalidArgumentException('timestamp é obrigatório para MARK_CLEAN.');
            }
            if (! in_array($action, ['SYNC', 'MARK_CLEAN'], true)
                && trim((string) ($payload['to'] ?? '')) === '') {
                throw new InvalidArgumentException('to é obrigatório para ação de chat 1:1.');
            }
        }
        if ($type === GatewayCommandType::RequestMediaRetry) {
            self::assertMediaRetryCommand($payload);
        }
    }

    /** @param array<string, mixed> $payload */
    public static function assertQuery(GatewayQueryType $type, array $payload): void
    {
        self::assertShape($payload, self::QUERY_SHAPES[$type->value], 'query '.$type->value);
        if (in_array($type, [
            GatewayQueryType::CheckUsers,
            GatewayQueryType::UserInfo,
            GatewayQueryType::BusinessProfile,
            GatewayQueryType::ContactProfiles,
        ], true)) {
            self::assertQueryUsers($payload['users'] ?? null, $type);
        }
    }

    /** @param array<string, mixed> $payload */
    public static function assertSafePayload(array $payload): void
    {
        self::assertObject($payload, 'resposta');
        self::assertSafeValue($payload, 'payload', 0);
    }

    /** @param array<string, mixed> $payload */
    public static function assertEvent(GatewayEventType $type, array $payload): void
    {
        self::assertObject($payload, 'evento');
        self::assertSafeValue($payload, 'payload', 0);
        match ($type) {
            GatewayEventType::MessageReceived => self::assertMessageReceived($payload),
            GatewayEventType::HistorySynced => self::assertHistory($payload),
            GatewayEventType::MediaRetryUpdated => self::assertMediaRetry($payload),
            GatewayEventType::MessageStatusChanged => self::assertMessageStatus($payload),
            GatewayEventType::MessageActionReceived => self::assertMessageAction($payload),
            GatewayEventType::ContactProfileChanged => self::assertContactProfile($payload),
            default => null,
        };
    }

    /** @return list<string> */
    public static function commandTypeValues(): array
    {
        return array_keys(self::COMMAND_SHAPES);
    }

    /** @return list<string> */
    public static function queryTypeValues(): array
    {
        return array_keys(self::QUERY_SHAPES);
    }

    public static function requiresProviderMessageId(GatewayCommandType $type): bool
    {
        return in_array($type, [
            GatewayCommandType::SendMessage,
            GatewayCommandType::EditMessage,
            GatewayCommandType::RevokeMessage,
            GatewayCommandType::ReactMessage,
            GatewayCommandType::VotePoll,
        ], true);
    }

    /** @return object|array<string, mixed> */
    public static function jsonObject(array $payload): object|array
    {
        return $payload === [] ? (object) [] : $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{allowed: list<string>, required: list<string>}  $shape
     */
    private static function assertShape(array $payload, array $shape, string $context): void
    {
        self::assertObject($payload, $context);

        $unknown = array_values(array_diff(array_keys($payload), $shape['allowed']));
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf(
                'Campo desconhecido em %s: %s.',
                $context,
                implode(', ', $unknown),
            ));
        }

        $missing = array_values(array_diff($shape['required'], array_keys($payload)));
        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Campo obrigatório ausente em %s: %s.',
                $context,
                implode(', ', $missing),
            ));
        }

        self::assertSafeValue($payload, 'payload', 0);
    }

    /** @param array<mixed> $payload */
    private static function assertObject(array $payload, string $context): void
    {
        if ($payload !== [] && array_is_list($payload)) {
            throw new InvalidArgumentException("Payload de {$context} deve ser objeto JSON.");
        }
    }

    private static function assertSafeValue(mixed $value, string $path, int $depth): void
    {
        if ($depth > 8) {
            throw new InvalidArgumentException("Payload excede profundidade máxima em {$path}.");
        }

        if ($value instanceof BackedEnum) {
            return;
        }

        if (is_null($value) || is_scalar($value)) {
            return;
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("Valor não serializável em {$path}.");
        }

        foreach ($value as $key => $child) {
            if (! is_int($key)) {
                if (! preg_match('/^[a-z][a-z0-9_]{0,63}$/', $key)) {
                    throw new InvalidArgumentException("Chave inválida em {$path}.");
                }
                if (in_array($key, self::FORBIDDEN_KEYS, true)) {
                    throw new InvalidArgumentException("Campo sensível não permitido em {$path}.{$key}.");
                }
            }
            self::assertSafeValue($child, $path.'.'.$key, $depth + 1);
        }
    }

    /** @param array<string, mixed> $payload */
    private static function assertMessageReceived(array $payload): void
    {
        $allowed = [
            'provider_message_id', 'from', 'source_identity', 'direction', 'history', 'occurred_at', 'kind',
            'provider_type', 'family', 'reply_to', 'reply_to_provider_message_id',
            'spool_id', 'media_size_bytes', 'media_sha256', 'mime_type', 'filename',
            'media_state', 'media_error_code', 'ephemeral', 'view_once',
            ...MessageSemanticContent::KEYS,
        ];
        self::assertAllowedKeys($payload, $allowed, 'MESSAGE_RECEIVED');
        $hasFrom = trim((string) ($payload['from'] ?? '')) !== '';
        $hasSourceIdentity = isset($payload['source_identity']);
        if (! $hasFrom && ! $hasSourceIdentity) {
            throw new InvalidArgumentException('from ou source_identity obrigatório em MESSAGE_RECEIVED.');
        }
        if ($hasSourceIdentity) {
            self::assertSourceIdentity($payload['source_identity'], 'MESSAGE_RECEIVED.source_identity');
        }
        $kind = MessageKind::tryFrom(strtoupper((string) ($payload['kind'] ?? '')));
        if ($kind === null || $kind === MessageKind::Note) {
            throw new InvalidArgumentException('kind inválido em MESSAGE_RECEIVED.');
        }
        $providerType = (string) ($payload['provider_type'] ?? '');
        if (! preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,79}$/', $providerType)) {
            throw new InvalidArgumentException('provider_type inválido em MESSAGE_RECEIVED.');
        }
        if (in_array(strtolower($providerType), self::TECHNICAL_MESSAGE_TYPES, true)) {
            throw new InvalidArgumentException('provider_type de controle inválido em MESSAGE_RECEIVED.');
        }
        if (isset($payload['direction'])
            && ! in_array(strtoupper((string) $payload['direction']), ['INBOUND', 'OUTBOUND'], true)) {
            throw new InvalidArgumentException('direction inválida em MESSAGE_RECEIVED.');
        }

        $family = strtoupper(trim((string) ($payload['family'] ?? '')));
        if (in_array($family, ['ACTION', 'CONTROL', 'OUT_OF_SCOPE'], true)) {
            throw new InvalidArgumentException('family técnica inválida em MESSAGE_RECEIVED.');
        }
        $expectedFamily = $kind === MessageKind::Unsupported ? 'UNSUPPORTED' : $kind->value;
        if ($family !== $expectedFamily) {
            throw new InvalidArgumentException('kind/family incoerentes em MESSAGE_RECEIVED.');
        }

        $content = MessageSemanticContent::fromEvent($payload, $kind);
        $hasSpool = trim((string) ($payload['spool_id'] ?? '')) !== '';
        $mediaState = strtoupper(trim((string) ($payload['media_state'] ?? '')));
        $hasExplicitMediaAbsence = in_array($mediaState, [
            'RETRY_AVAILABLE', 'REQUESTED', 'FAILED', 'UNAVAILABLE',
        ], true);
        if ($mediaState !== '' && ! in_array($mediaState, [
            'RETRY_AVAILABLE', 'REQUESTED', 'READY', 'FAILED', 'UNAVAILABLE',
        ], true)) {
            throw new InvalidArgumentException('media_state inválido em MESSAGE_RECEIVED.');
        }

        match ($kind) {
            MessageKind::Text => trim((string) ($payload['text'] ?? '')) !== ''
                ?: throw new InvalidArgumentException('text obrigatório em MESSAGE_RECEIVED TEXT.'),
            MessageKind::Image, MessageKind::Audio, MessageKind::Video,
            MessageKind::Document, MessageKind::Sticker => ($hasSpool xor $hasExplicitMediaAbsence)
                ?: throw new InvalidArgumentException('Mídia exige spool completo ou estado de ausência exclusivo.'),
            MessageKind::Location => isset($content['location'])
                ?: throw new InvalidArgumentException('location obrigatório em MESSAGE_RECEIVED LOCATION.'),
            MessageKind::Contact => isset($content['contacts'])
                ?: throw new InvalidArgumentException('contacts obrigatório em MESSAGE_RECEIVED CONTACT.'),
            MessageKind::Poll => isset($content['poll'])
                ?: throw new InvalidArgumentException('poll obrigatório em MESSAGE_RECEIVED POLL.'),
            MessageKind::Interactive => isset($content['interactive'])
                ?: throw new InvalidArgumentException('interactive obrigatório em MESSAGE_RECEIVED INTERACTIVE.'),
            MessageKind::Unsupported => (($payload['content_present'] ?? false) === true)
                ?: throw new InvalidArgumentException('UNSUPPORTED exige content_present=true.'),
            MessageKind::Note => throw new InvalidArgumentException('NOTE inválida em MESSAGE_RECEIVED.'),
        };

        if ($hasSpool) {
            foreach (['media_size_bytes', 'media_sha256', 'mime_type'] as $required) {
                if (! array_key_exists($required, $payload)) {
                    throw new InvalidArgumentException("{$required} obrigatório com spool_id.");
                }
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private static function assertMediaRetryCommand(array $payload): void
    {
        $legacy = array_key_exists('sender', $payload) || array_key_exists('from_me', $payload);
        $v2 = array_key_exists('expected_direction', $payload);
        if ($legacy === $v2) {
            throw new InvalidArgumentException('MEDIA_RETRY_REQUEST exige shape legado ou v2 exclusivo.');
        }
        if ($legacy) {
            if (trim((string) ($payload['sender'] ?? '')) === ''
                || ! array_key_exists('from_me', $payload)
                || $payload['from_me'] !== false) {
                throw new InvalidArgumentException('MEDIA_RETRY_REQUEST legado aceita somente inbound válido.');
            }

            return;
        }
        if (! in_array(strtoupper((string) $payload['expected_direction']), ['INBOUND', 'OUTBOUND'], true)) {
            throw new InvalidArgumentException('expected_direction inválida em MEDIA_RETRY_REQUEST.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function assertHistory(array $payload): void
    {
        self::assertAllowedKeys($payload, [
            'batch_id', 'complete', 'messages', 'sync_type', 'chunk_order', 'progress',
            'message_count', 'rejected_count', 'truncated',
            'sync_id', 'segment_id', 'segment_index', 'segment_count', 'source_progress',
        ], 'HISTORY_SYNCED');
        if (isset($payload['source_progress'])) {
            self::assertSourceProgress($payload['source_progress']);
        }
        foreach (['segment_index', 'segment_count', 'chunk_order', 'message_count', 'rejected_count', 'progress'] as $numeric) {
            if (! array_key_exists($numeric, $payload)) {
                continue;
            }
            if (! is_int($payload[$numeric]) && ! (is_float($payload[$numeric]) && floor($payload[$numeric]) == $payload[$numeric])) {
                throw new InvalidArgumentException("{$numeric} inválido em HISTORY_SYNCED.");
            }
            if ((int) $payload[$numeric] < 0) {
                throw new InvalidArgumentException("{$numeric} inválido em HISTORY_SYNCED.");
            }
        }
        if (isset($payload['segment_count']) && (int) $payload['segment_count'] < 1) {
            throw new InvalidArgumentException('segment_count inválido em HISTORY_SYNCED.');
        }
        if (isset($payload['segment_index'], $payload['segment_count'])
            && (int) $payload['segment_index'] >= (int) $payload['segment_count']) {
            throw new InvalidArgumentException('segment_index inválido em HISTORY_SYNCED.');
        }
        foreach (['sync_id', 'segment_id'] as $idField) {
            if (! isset($payload[$idField])) {
                continue;
            }
            if (! is_string($payload[$idField]) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $payload[$idField]) !== 1) {
                throw new InvalidArgumentException("{$idField} inválido em HISTORY_SYNCED.");
            }
        }
        if (isset($payload['messages'])) {
            if (! is_array($payload['messages']) || count($payload['messages']) > 100) {
                throw new InvalidArgumentException('messages inválido em HISTORY_SYNCED.');
            }
            foreach ($payload['messages'] as $message) {
                if (! is_array($message)) {
                    throw new InvalidArgumentException('Mensagem inválida em HISTORY_SYNCED.');
                }
                self::assertMessageReceived($message);
            }
        }
    }

    private static function assertSourceIdentity(mixed $value, string $context): void
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("{$context} deve ser objeto.");
        }
        self::assertAllowedKeys($value, [
            'primary', 'primary_kind', 'alternate', 'alternate_kind', 'evidence',
        ], $context);
        $primary = trim((string) ($value['primary'] ?? ''));
        $primaryKind = strtoupper(trim((string) ($value['primary_kind'] ?? '')));
        if ($primary === '' || ! in_array($primaryKind, ['PN', 'LID'], true)) {
            throw new InvalidArgumentException("primary/primary_kind inválidos em {$context}.");
        }
        if ($primaryKind === 'PN' && preg_match('/^\+[1-9][0-9]{7,14}$/', $primary) !== 1) {
            throw new InvalidArgumentException("primary PN inválido em {$context}.");
        }
        if ($primaryKind === 'LID' && preg_match('/^lid:[1-9][0-9]{0,19}$/', $primary) !== 1) {
            throw new InvalidArgumentException("primary LID inválido em {$context}.");
        }
        $hasAlternateValue = array_key_exists('alternate', $value);
        $hasAlternateKind = array_key_exists('alternate_kind', $value);
        if ($hasAlternateValue !== $hasAlternateKind) {
            throw new InvalidArgumentException("alternate/alternate_kind incompletos em {$context}.");
        }
        $hasAlternate = $hasAlternateValue;
        if ($hasAlternate) {
            $alternate = trim((string) ($value['alternate'] ?? ''));
            $alternateKind = strtoupper(trim((string) ($value['alternate_kind'] ?? '')));
            if ($alternate === '' || ! in_array($alternateKind, ['PN', 'LID'], true)) {
                throw new InvalidArgumentException("alternate/alternate_kind inválidos em {$context}.");
            }
            if ($alternateKind === 'PN' && preg_match('/^\+[1-9][0-9]{7,14}$/', $alternate) !== 1) {
                throw new InvalidArgumentException("alternate PN inválido em {$context}.");
            }
            if ($alternateKind === 'LID' && preg_match('/^lid:[1-9][0-9]{0,19}$/', $alternate) !== 1) {
                throw new InvalidArgumentException("alternate LID inválido em {$context}.");
            }
            if ($primaryKind !== 'LID'
                || $alternateKind !== 'PN'
                || hash_equals($primary, $alternate)
                || ($value['evidence'] ?? null) !== 'MESSAGE_SOURCE_ALT') {
                throw new InvalidArgumentException("Associação LID/PN inválida em {$context}.");
            }
        }
        if (isset($value['evidence'])
            && (! is_string($value['evidence']) || preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', $value['evidence']) !== 1)) {
            throw new InvalidArgumentException("evidence inválido em {$context}.");
        }
    }

    private static function assertQueryUsers(mixed $users, GatewayQueryType $type): void
    {
        if (! is_array($users) || ! array_is_list($users)
            || count($users) < 1 || count($users) > 100
            || count(array_unique($users, SORT_STRING)) !== count($users)) {
            throw new InvalidArgumentException("users inválido em query {$type->value}.");
        }
        foreach ($users as $user) {
            if (! is_string($user)
                || preg_match('/^(?:\+[1-9][0-9]{7,14}|lid:[1-9][0-9]{0,19})$/', $user) !== 1) {
                throw new InvalidArgumentException("Endereço 1:1 inválido em query {$type->value}.");
            }
        }
    }

    /** @param array<string, mixed> $payload */
    private static function assertContactProfile(array $payload): void
    {
        self::assertAllowedKeys($payload, [
            'user', 'display_name', 'address_book_name', 'address_book_first_name',
            'address_book_full_name', 'verified_name', 'business_name', 'push_name',
            'picture_id', 'about', 'source', 'cleared_fields', 'from_full_sync',
            'source_identity',
        ], 'CONTACT_PROFILE_CHANGED');

        $user = $payload['user'] ?? null;
        if (! is_string($user)
            || preg_match('/^(?:\+[1-9][0-9]{7,14}|lid:[1-9][0-9]{0,19})$/', $user) !== 1) {
            throw new InvalidArgumentException('user inválido em CONTACT_PROFILE_CHANGED.');
        }
        if (isset($payload['source']) && ! in_array($payload['source'], [
            'PUSH', 'ADDRESS_BOOK', 'BUSINESS', 'VERIFIED', 'PICTURE', 'ABOUT',
        ], true)) {
            throw new InvalidArgumentException('source inválido em CONTACT_PROFILE_CHANGED.');
        }
        foreach ([
            'display_name' => 512,
            'address_book_name' => 512,
            'address_book_first_name' => 512,
            'address_book_full_name' => 512,
            'verified_name' => 512,
            'business_name' => 512,
            'push_name' => 512,
            'picture_id' => 512,
            'about' => 2048,
        ] as $field => $limit) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }
            if (! is_string($payload[$field]) || mb_strlen($payload[$field]) > $limit) {
                throw new InvalidArgumentException("{$field} inválido em CONTACT_PROFILE_CHANGED.");
            }
        }
        if (isset($payload['from_full_sync']) && ! is_bool($payload['from_full_sync'])) {
            throw new InvalidArgumentException('from_full_sync inválido em CONTACT_PROFILE_CHANGED.');
        }
        if (isset($payload['source_identity'])) {
            self::assertSourceIdentity($payload['source_identity'], 'CONTACT_PROFILE_CHANGED.source_identity');
        }
        if (isset($payload['cleared_fields'])) {
            $cleared = $payload['cleared_fields'];
            $allowed = [
                'address_book_first_name', 'address_book_full_name', 'verified_name',
                'business_name', 'push_name', 'picture_id', 'about',
            ];
            if (! is_array($cleared) || ! array_is_list($cleared) || count($cleared) > 16
                || count(array_unique($cleared, SORT_STRING)) !== count($cleared)) {
                throw new InvalidArgumentException('cleared_fields inválido em CONTACT_PROFILE_CHANGED.');
            }
            foreach ($cleared as $field) {
                if (! is_string($field) || ! in_array($field, $allowed, true)) {
                    throw new InvalidArgumentException('Campo removido inválido em CONTACT_PROFILE_CHANGED.');
                }
            }
        }
    }

    private static function assertSourceProgress(mixed $value): void
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException('source_progress deve ser objeto em HISTORY_SYNCED.');
        }
        self::assertAllowedKeys($value, ['percent', 'upstream_complete'], 'HISTORY_SYNCED.source_progress');
        if (isset($value['percent'])) {
            $percent = $value['percent'];
            if ((! is_int($percent) && ! (is_float($percent) && floor($percent) == $percent))
                || (int) $percent < 0 || (int) $percent > 100) {
                throw new InvalidArgumentException('source_progress.percent inválido em HISTORY_SYNCED.');
            }
        }
        if (isset($value['upstream_complete']) && ! is_bool($value['upstream_complete'])) {
            throw new InvalidArgumentException('source_progress.upstream_complete inválido em HISTORY_SYNCED.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function assertMediaRetry(array $payload): void
    {
        self::assertAllowedKeys($payload, [
            'provider_message_id', 'status', 'generation', 'attempt', 'spool_id',
            'size_bytes', 'sha256', 'mime_type', 'filename', 'error_code',
        ], 'MEDIA_RETRY_UPDATED');
        $status = strtoupper((string) ($payload['status'] ?? ''));
        if (! in_array($status, ['REQUESTED', 'FAILED', 'READY'], true)) {
            throw new InvalidArgumentException('status inválido em MEDIA_RETRY_UPDATED.');
        }
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', (string) ($payload['provider_message_id'] ?? ''))) {
            throw new InvalidArgumentException('provider_message_id inválido em MEDIA_RETRY_UPDATED.');
        }
        if ($status === 'READY') {
            foreach (['provider_message_id', 'spool_id', 'size_bytes', 'sha256', 'mime_type'] as $required) {
                if (! array_key_exists($required, $payload)) {
                    throw new InvalidArgumentException("{$required} obrigatório em MEDIA_RETRY_UPDATED READY.");
                }
            }
        }
        if ($status !== 'READY' && array_key_exists('spool_id', $payload)) {
            throw new InvalidArgumentException('spool_id só é permitido em MEDIA_RETRY_UPDATED READY.');
        }
        if ($status === 'FAILED' && ! in_array(
            (string) ($payload['error_code'] ?? ''),
            self::MEDIA_RETRY_FAILURE_CODES,
            true,
        )) {
            throw new InvalidArgumentException('error_code inválido em MEDIA_RETRY_UPDATED FAILED.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function assertMessageStatus(array $payload): void
    {
        self::assertAllowedKeys($payload, ['provider_message_id', 'status', 'error_code'], 'MESSAGE_STATUS_CHANGED');
        if (! in_array(strtoupper((string) ($payload['status'] ?? '')), [
            'QUEUED', 'ACCEPTED', 'SENT', 'DELIVERED', 'READ', 'PLAYED', 'FAILED', 'UNKNOWN', 'CANCELED',
        ], true)) {
            throw new InvalidArgumentException('status inválido em MESSAGE_STATUS_CHANGED.');
        }
        if (isset($payload['error_code'])
            && preg_match('/^[A-Z][A-Z0-9_]{2,79}$/', (string) $payload['error_code']) !== 1) {
            throw new InvalidArgumentException('error_code inválido em MESSAGE_STATUS_CHANGED.');
        }
    }

    /** @param array<string,mixed> $payload */
    private static function assertMessageAction(array $payload): void
    {
        self::assertAllowedKeys($payload, [
            'action', 'provider_message_id', 'target_message_id', 'from', 'source_identity', 'history',
            'kind', 'provider_type', 'family', 'text', 'caption', 'link_preview', 'location',
            'contacts', 'poll', 'interactive', 'ptt', 'gif', 'animated', 'duration_seconds',
            'content_present', 'variants', 'emoji', 'removed', 'option_names', 'option_hashes',
            'selected_id',
        ], 'MESSAGE_ACTION_RECEIVED');
        foreach (['action', 'target_message_id'] as $required) {
            if (! isset($payload[$required]) || trim((string) $payload[$required]) === '') {
                throw new InvalidArgumentException("{$required} obrigatório em MESSAGE_ACTION_RECEIVED.");
            }
        }
        $hasFrom = trim((string) ($payload['from'] ?? '')) !== '';
        $hasSourceIdentity = isset($payload['source_identity']);
        if (! $hasFrom && ! $hasSourceIdentity) {
            throw new InvalidArgumentException('from ou source_identity obrigatório em MESSAGE_ACTION_RECEIVED.');
        }
        if ($hasSourceIdentity) {
            self::assertSourceIdentity($payload['source_identity'], 'MESSAGE_ACTION_RECEIVED.source_identity');
        }
        $action = strtoupper((string) $payload['action']);
        if (! in_array($action, ['EDIT', 'REVOKE', 'REACTION', 'POLL_VOTE', 'INTERACTIVE_RESPONSE'], true)) {
            throw new InvalidArgumentException('action inválida em MESSAGE_ACTION_RECEIVED.');
        }
        if ($action === 'EDIT') {
            $kind = MessageKind::tryFrom(strtoupper((string) ($payload['kind'] ?? '')));
            if ($kind === null || $kind === MessageKind::Note
                || ! preg_match('/^[A-Za-z][A-Za-z0-9._-]{0,79}$/', (string) ($payload['provider_type'] ?? ''))) {
                throw new InvalidArgumentException('Conteúdo de EDIT inválido.');
            }
            MessageSemanticContent::fromEvent($payload, $kind);
        }
        if ($action === 'REACTION' && isset($payload['emoji'])
            && (! is_string($payload['emoji']) || mb_strlen($payload['emoji']) > 32)) {
            throw new InvalidArgumentException('emoji inválido.');
        }
        if ($action === 'POLL_VOTE') {
            foreach (is_array($payload['option_hashes'] ?? null) ? $payload['option_hashes'] : [] as $hash) {
                if (! is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                    throw new InvalidArgumentException('option_hashes inválido.');
                }
            }
        }
    }

    /** @param array<string, mixed> $payload @param list<string> $allowed */
    private static function assertAllowedKeys(array $payload, array $allowed, string $context): void
    {
        $unknown = array_diff(array_keys($payload), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException("Campo desconhecido em {$context}: ".implode(', ', $unknown).'.');
        }
    }

    /** @param array<string,mixed> $payload */
    private static function assertOutboundMessage(array $payload): void
    {
        $kind = MessageKind::tryFrom(strtoupper((string) ($payload['kind'] ?? '')));
        if ($kind === null || in_array($kind, [MessageKind::Note, MessageKind::Unsupported], true)) {
            throw new InvalidArgumentException('MESSAGE_KIND_UNSUPPORTED');
        }
        $required = match ($kind) {
            MessageKind::Text => 'text',
            MessageKind::Image, MessageKind::Audio, MessageKind::Video,
            MessageKind::Document, MessageKind::Sticker => 'media',
            MessageKind::Location => 'location',
            MessageKind::Contact => 'contact',
            MessageKind::Poll => 'poll',
            MessageKind::Interactive => 'interactive',
            default => throw new InvalidArgumentException('MESSAGE_KIND_UNSUPPORTED'),
        };
        if (! isset($payload[$required])) {
            throw new InvalidArgumentException("{$required} obrigatório para {$kind->value}.");
        }
        foreach (['text', 'media', 'location', 'contact', 'poll', 'interactive'] as $field) {
            if ($field !== $required && isset($payload[$field])
                && ! ($kind === MessageKind::Text && $field === 'text')) {
                throw new InvalidArgumentException("{$field} incompatível com {$kind->value}.");
            }
        }
        if (isset($payload['media'])) {
            self::assertObject($payload['media'], 'media');
            self::assertAllowedKeys($payload['media'], [
                'attachment_id', 'mime_type', 'filename', 'size_bytes', 'sha256', 'ptt',
            ], 'media');
            $mime = strtolower((string) ($payload['media']['mime_type'] ?? ''));
            $mimeAllowed = match ($kind) {
                MessageKind::Image => str_starts_with($mime, 'image/'),
                MessageKind::Audio => str_starts_with($mime, 'audio/'),
                MessageKind::Video => str_starts_with($mime, 'video/'),
                MessageKind::Sticker => $mime === 'image/webp',
                MessageKind::Document => $mime !== '',
                default => false,
            };
            if (! $mimeAllowed || (($payload['media']['ptt'] ?? false) && $kind !== MessageKind::Audio)) {
                throw new InvalidArgumentException('MIME/PTT incompatível com kind.');
            }
        }
    }
}

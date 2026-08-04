<?php

namespace App\DTO\Communication;

use App\Enums\Communication\MessageKind;
use InvalidArgumentException;

final class MessageSemanticContent
{
    /** @var list<string> */
    public const KEYS = [
        'text', 'caption', 'link_preview', 'location', 'contacts', 'poll', 'interactive', 'rich_card',
        'ptt', 'gif', 'animated', 'duration_seconds', 'content_present', 'variants',
    ];

    /** @var list<string> */
    public const STORED_KEYS = [
        ...self::KEYS, 'reactions', 'poll_votes', 'interactive_response',
    ];

    /** @param array<string, mixed> $payload */
    public static function fromEvent(array $payload, MessageKind $kind): array
    {
        $content = array_intersect_key($payload, array_flip(self::KEYS));
        self::assertShape($content, $kind);

        return array_filter($content, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /** @param array<string, mixed> $content */
    public static function assertShape(array $content, MessageKind $kind): void
    {
        self::rejectUnknown($content, self::STORED_KEYS, 'content');
        foreach (['text', 'caption'] as $field) {
            if (isset($content[$field]) && (! is_string($content[$field]) || strlen($content[$field]) > 65_536)) {
                throw new InvalidArgumentException("{$field} inválido.");
            }
        }
        foreach (['ptt', 'gif', 'animated', 'content_present'] as $field) {
            if (isset($content[$field]) && ! is_bool($content[$field])) {
                throw new InvalidArgumentException("{$field} inválido.");
            }
        }
        if (isset($content['duration_seconds']) && (! is_int($content['duration_seconds']) || $content['duration_seconds'] < 0)) {
            throw new InvalidArgumentException('duration_seconds inválido.');
        }
        if (isset($content['variants']) && (! is_array($content['variants']) || count($content['variants']) > 16
            || array_filter($content['variants'], 'is_string') !== $content['variants'])) {
            throw new InvalidArgumentException('variants inválido.');
        }
        if (isset($content['link_preview'])) {
            self::assertObject($content['link_preview'], ['url', 'title', 'description'], 'link_preview');
            $url = filter_var($content['link_preview']['url'] ?? null, FILTER_VALIDATE_URL);
            $scheme = is_string($url) ? parse_url($url, PHP_URL_SCHEME) : null;
            if (! is_string($url) || ! in_array($scheme, ['http', 'https'], true) || strlen($url) > 2048) {
                throw new InvalidArgumentException('URL de link_preview inválida.');
            }
            self::assertStrings($content['link_preview'], ['title' => 4096, 'description' => 8192], 'link_preview');
        }
        if (isset($content['location'])) {
            self::assertObject($content['location'], [
                'latitude', 'longitude', 'name', 'address', 'caption', 'live', 'accuracy_meters', 'sequence',
            ], 'location');
            if (! is_numeric($content['location']['latitude'] ?? null)
                || ! is_numeric($content['location']['longitude'] ?? null)
                || (float) $content['location']['latitude'] < -90 || (float) $content['location']['latitude'] > 90
                || (float) $content['location']['longitude'] < -180 || (float) $content['location']['longitude'] > 180) {
                throw new InvalidArgumentException('Coordenadas inválidas.');
            }
            self::assertStrings($content['location'], ['name' => 1024, 'address' => 4096, 'caption' => 4096], 'location');
            if (isset($content['location']['live']) && ! is_bool($content['location']['live'])) {
                throw new InvalidArgumentException('location.live inválido.');
            }
        }
        if (isset($content['contacts']) && (! is_array($content['contacts']) || count($content['contacts']) > 50)) {
            throw new InvalidArgumentException('contacts inválido.');
        }
        foreach (is_array($content['contacts'] ?? null) ? $content['contacts'] : [] as $contact) {
            self::assertObject($contact, ['display_name', 'vcard', 'phones'], 'contact');
            self::assertRequiredStrings($contact, ['display_name' => 1024, 'vcard' => 65_536], 'contact');
            if (isset($contact['phones'])) {
                if (! is_array($contact['phones']) || count($contact['phones']) > 10) {
                    throw new InvalidArgumentException('contact.phones inválido.');
                }
                foreach ($contact['phones'] as $phone) {
                    self::assertObject($phone, ['label', 'phone'], 'contact.phone');
                    self::assertStrings($phone, ['label' => 40, 'phone' => 20], 'contact.phone');
                    if (! preg_match('/^\+[1-9][0-9]{7,14}$/', (string) ($phone['phone'] ?? ''))) {
                        throw new InvalidArgumentException('contact.phone inválido.');
                    }
                }
            }
        }
        if (isset($content['poll'])) {
            self::assertObject($content['poll'], ['name', 'options', 'selectable_options'], 'poll');
            self::assertRequiredStrings($content['poll'], ['name' => 4096], 'poll');
            $options = $content['poll']['options'] ?? null;
            if (! is_array($options) || count($options) < 1 || count($options) > 12
                || array_filter($options, static fn (mixed $value): bool => is_string($value) && strlen($value) <= 1024) !== $options) {
                throw new InvalidArgumentException('poll.options inválido.');
            }
            if (isset($content['poll']['selectable_options'])
                && (! is_int($content['poll']['selectable_options']) || $content['poll']['selectable_options'] < 0
                    || $content['poll']['selectable_options'] > count($options))) {
                throw new InvalidArgumentException('poll.selectable_options inválido.');
            }
        }
        if (isset($content['interactive'])) {
            self::assertObject($content['interactive'], [
                'mode', 'title', 'description', 'selected_id', 'display_text', 'name',
            ], 'interactive');
            self::assertStrings($content['interactive'], [
                'mode' => 64, 'title' => 4096, 'description' => 4096,
                'selected_id' => 1024, 'display_text' => 4096, 'name' => 1024,
            ], 'interactive');
            if (! preg_match('/^[A-Z][A-Z0-9_]{1,63}$/', (string) ($content['interactive']['mode'] ?? ''))) {
                throw new InvalidArgumentException('interactive.mode inválido.');
            }
        }
        if (isset($content['rich_card'])) {
            self::assertObject($content['rich_card'], ['category', 'title', 'description', 'facts'], 'rich_card');
            self::assertRequiredStrings($content['rich_card'], ['category' => 16, 'title' => 4096], 'rich_card');
            self::assertStrings($content['rich_card'], ['description' => 8192], 'rich_card');
            if (! in_array($content['rich_card']['category'] ?? null, [
                'PRODUCT', 'ORDER', 'PAYMENT', 'EVENT', 'CALL', 'INVITE', 'SYSTEM',
            ], true)) {
                throw new InvalidArgumentException('rich_card.category inválido.');
            }
            $facts = $content['rich_card']['facts'] ?? [];
            if (! is_array($facts) || count($facts) > 12) {
                throw new InvalidArgumentException('rich_card.facts inválido.');
            }
            foreach ($facts as $fact) {
                self::assertObject($fact, ['label', 'value'], 'rich_card.fact');
                self::assertRequiredStrings($fact, ['label' => 64, 'value' => 1024], 'rich_card.fact');
            }
        }
        if (isset($content['reactions'])) {
            if (! is_array($content['reactions'])) {
                throw new InvalidArgumentException('reactions inválido.');
            }
            foreach ($content['reactions'] as $actor => $emoji) {
                if (! is_string($actor) || ! is_string($emoji) || mb_strlen($emoji) > 32) {
                    throw new InvalidArgumentException('reactions inválido.');
                }
            }
        }
        if (isset($content['poll_votes'])) {
            if (! is_array($content['poll_votes'])) {
                throw new InvalidArgumentException('poll_votes inválido.');
            }
            foreach ($content['poll_votes'] as $vote) {
                self::assertObject($vote, ['option_names', 'option_hashes'], 'poll_vote');
                foreach (['option_names', 'option_hashes'] as $field) {
                    if (! isset($vote[$field]) || ! is_array($vote[$field]) || ! array_is_list($vote[$field]) || count($vote[$field]) > 12) {
                        throw new InvalidArgumentException("poll_vote.{$field} inválido.");
                    }
                }
                if (array_filter($vote['option_names'], static fn (mixed $value): bool => is_string($value) && strlen($value) <= 1024) !== $vote['option_names']) {
                    throw new InvalidArgumentException('poll_vote.option_names inválido.');
                }
                if (array_filter($vote['option_hashes'], static fn (mixed $value): bool => is_string($value) && preg_match('/^[a-f0-9]{64}$/', $value) === 1) !== $vote['option_hashes']) {
                    throw new InvalidArgumentException('poll_vote.option_hashes inválido.');
                }
            }
        }
        if (isset($content['interactive_response'])) {
            self::assertObject($content['interactive_response'], ['text', 'selected_id'], 'interactive_response');
            self::assertStrings($content['interactive_response'], ['text' => 4096, 'selected_id' => 1024], 'interactive_response');
        }
        if ($kind === MessageKind::Unsupported && ! isset($content['content_present']) && ! isset($content['variants'])) {
            throw new InvalidArgumentException('UNSUPPORTED exige envelope semântico limitado.');
        }
    }

    /** @param list<string> $allowed */
    private static function assertObject(mixed $value, array $allowed, string $field): void
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException("{$field} deve ser objeto.");
        }
        self::rejectUnknown($value, $allowed, $field);
    }

    /** @param array<string, mixed> $value @param list<string> $allowed */
    private static function rejectUnknown(array $value, array $allowed, string $field): void
    {
        $unknown = array_diff(array_keys($value), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException("Campo não permitido em {$field}: ".implode(', ', $unknown).'.');
        }
    }

    /** @param array<string, mixed> $value @param array<string, int> $fields */
    private static function assertStrings(array $value, array $fields, string $context): void
    {
        foreach ($fields as $field => $limit) {
            if (isset($value[$field]) && (! is_string($value[$field]) || strlen($value[$field]) > $limit)) {
                throw new InvalidArgumentException("{$context}.{$field} inválido.");
            }
        }
    }

    /** @param array<string, mixed> $value @param array<string, int> $fields */
    private static function assertRequiredStrings(array $value, array $fields, string $context): void
    {
        foreach ($fields as $field => $limit) {
            if (! array_key_exists($field, $value) || ! is_string($value[$field]) || strlen($value[$field]) > $limit) {
                throw new InvalidArgumentException("{$context}.{$field} inválido.");
            }
        }
    }
}

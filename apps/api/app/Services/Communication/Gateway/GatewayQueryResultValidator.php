<?php

namespace App\Services\Communication\Gateway;

use App\Enums\Communication\GatewayQueryType;
use InvalidArgumentException;

final class GatewayQueryResultValidator
{
    /** @param array<string, mixed> $result */
    public function assertValid(GatewayQueryType $type, array $result): void
    {
        match ($type) {
            GatewayQueryType::CheckUsers => $this->usersResult(
                $result,
                'users',
                ['input', 'exists', 'user', 'verified_name'],
                ['input', 'exists'],
            ),
            GatewayQueryType::UserInfo => $this->usersResult(
                $result,
                'user_info',
                ['user', 'status', 'verified_name'],
                ['user'],
            ),
            GatewayQueryType::BusinessProfile => $this->usersResult(
                $result,
                'business_profiles',
                ['user', 'name', 'description', 'email', 'website', 'category'],
                ['user'],
            ),
            GatewayQueryType::ProfilePicture => $this->profilePicture($result),
            GatewayQueryType::ContactQrLink => $this->objectResult(
                $result,
                'contact_qr_link',
                ['link', 'expires_at'],
                ['link'],
            ),
            GatewayQueryType::ResolveContactQr => $this->objectResult(
                $result,
                'contact',
                ['user', 'prefilled_text'],
                ['user'],
            ),
            GatewayQueryType::ResolveBusinessLink => $this->objectResult(
                $result,
                'business',
                ['user', 'message'],
                ['user'],
            ),
            GatewayQueryType::Blocklist => $this->blocklist($result),
            GatewayQueryType::PrivacySettings => $this->privacy($result),
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $allowedItemKeys
     * @param  list<string>  $requiredItemKeys
     */
    private function usersResult(
        array $result,
        string $key,
        array $allowedItemKeys,
        array $requiredItemKeys,
    ): void {
        $this->assertObjectKeys($result, [$key], [$key], 'result');
        $items = $result[$key];
        if (! is_array($items) || ! array_is_list($items) || count($items) > 100) {
            throw new InvalidArgumentException('Lista de resultado inválida.');
        }
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Item de resultado inválido.');
            }
            $this->assertObjectKeys($item, $allowedItemKeys, $requiredItemKeys, $key);
            foreach ($item as $field => $value) {
                if ($field === 'exists') {
                    if (! is_bool($value)) {
                        throw new InvalidArgumentException('Campo exists inválido.');
                    }

                    continue;
                }
                if (! is_string($value)) {
                    throw new InvalidArgumentException("Campo {$field} inválido.");
                }
                if (in_array($field, ['input', 'user'], true)) {
                    $this->assertAddress($value);
                }
            }
        }
    }

    /** @param array<string, mixed> $result */
    private function profilePicture(array $result): void
    {
        $this->assertObjectKeys($result, ['profile_picture'], ['profile_picture'], 'result');
        $picture = $result['profile_picture'];
        if ($picture === null) {
            return;
        }
        if (! is_array($picture)) {
            throw new InvalidArgumentException('Profile picture inválida.');
        }
        $this->assertObjectKeys($picture, ['user', 'url', 'id'], ['user', 'url'], 'profile_picture');
        $this->assertAddress($picture['user']);
        $this->assertString($picture['url'], 'url');
        if (isset($picture['id'])) {
            $this->assertString($picture['id'], 'id');
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  list<string>  $allowed
     * @param  list<string>  $required
     */
    private function objectResult(array $result, string $key, array $allowed, array $required): void
    {
        $this->assertObjectKeys($result, [$key], [$key], 'result');
        $object = $result[$key];
        if (! is_array($object)) {
            throw new InvalidArgumentException("Objeto {$key} inválido.");
        }
        $this->assertObjectKeys($object, $allowed, $required, $key);
        foreach ($object as $field => $value) {
            $this->assertString($value, $field);
            if ($field === 'user') {
                $this->assertAddress($value);
            }
        }
    }

    /** @param array<string, mixed> $result */
    private function blocklist(array $result): void
    {
        $this->assertObjectKeys($result, ['blocked_users'], ['blocked_users'], 'result');
        $users = $result['blocked_users'];
        if (! is_array($users) || ! array_is_list($users) || count($users) > 10_000) {
            throw new InvalidArgumentException('Blocklist inválida.');
        }
        foreach ($users as $user) {
            $this->assertAddress($user);
        }
    }

    /** @param array<string, mixed> $result */
    private function privacy(array $result): void
    {
        $this->assertObjectKeys($result, ['settings'], ['settings'], 'result');
        $settings = $result['settings'];
        if (! is_array($settings) || ! array_is_list($settings) || count($settings) > 16) {
            throw new InvalidArgumentException('Privacy settings inválidos.');
        }
        foreach ($settings as $setting) {
            if (! is_array($setting)) {
                throw new InvalidArgumentException('Privacy setting inválido.');
            }
            $this->assertObjectKeys($setting, ['name', 'value'], ['name', 'value'], 'settings');
            if (! in_array($setting['name'], ['last', 'profile', 'readreceipts', 'online'], true)
                || ! in_array($setting['value'], ['all', 'contacts', 'contact_blacklist', 'none', 'match_last_seen'], true)) {
                throw new InvalidArgumentException('Privacy setting fora da matriz permitida.');
            }
        }
    }

    /**
     * @param  array<mixed>  $value
     * @param  list<string>  $allowed
     * @param  list<string>  $required
     */
    private function assertObjectKeys(array $value, array $allowed, array $required, string $context): void
    {
        if ($value !== [] && array_is_list($value)) {
            throw new InvalidArgumentException("{$context} deve ser objeto.");
        }
        if (array_diff(array_keys($value), $allowed) !== [] || array_diff($required, array_keys($value)) !== []) {
            throw new InvalidArgumentException("Schema de {$context} inválido.");
        }
    }

    private function assertAddress(mixed $value): void
    {
        if (! is_string($value)
            || ! preg_match('/^(?:\+[1-9][0-9]{7,14}|lid:[1-9][0-9]{0,19})$/', $value)) {
            throw new InvalidArgumentException('Endereço 1:1 inválido na resposta.');
        }
    }

    private function assertString(mixed $value, string $field): void
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException("Campo {$field} inválido.");
        }
    }
}

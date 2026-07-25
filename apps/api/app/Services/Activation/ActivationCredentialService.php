<?php

namespace App\Services\Activation;

use App\Enums\ActivationMethod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Geração e verificação de segredos de ativação.
 * Plaintext só em memória; nunca logar.
 */
final class ActivationCredentialService
{
    public const LINK_TTL_DAYS = 7;

    public const TEMP_PASSWORD_TTL_DAYS = 7;

    /**
     * Token de alta entropia (32 bytes → 64 hex).
     */
    public function generateLinkToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Senha provisória legível e com entropia suficiente (~80 bits).
     */
    public function generateTemporaryPassword(): string
    {
        // 5 grupos de 4 chars alfanuméricos sem ambíguos.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $chunks = [];
        for ($g = 0; $g < 4; $g++) {
            $chunk = '';
            for ($i = 0; $i < 4; $i++) {
                $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $chunks[] = $chunk;
        }

        return implode('-', $chunks);
    }

    public function hashToken(string $plain): string
    {
        return hash_hmac('sha256', $plain, (string) config('app.key'));
    }

    public function hashPassword(string $plain): string
    {
        return Hash::make($plain);
    }

    public function verifyToken(string $plain, string $hash): bool
    {
        if ($plain === '' || $hash === '') {
            return false;
        }

        return hash_equals($hash, $this->hashToken($plain));
    }

    public function verifyPassword(string $plain, string $hash): bool
    {
        if ($plain === '' || $hash === '') {
            return false;
        }

        return Hash::check($plain, $hash);
    }

    /**
     * Hash-sentinela individual não autenticável (descarta plaintext).
     */
    public function makeSentinelPasswordHash(): string
    {
        return Hash::make(bin2hex(random_bytes(32)));
    }

    public function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    public function expiresAtFor(): Carbon
    {
        return now()->addDays(self::LINK_TTL_DAYS);
    }

    /**
     * @return array{plain: string, hash: string, method: string, activation_url?: string, temporary_password?: string}
     */
    public function issueSecret(ActivationMethod $method): array
    {
        if ($method === ActivationMethod::ManualLink) {
            $plain = $this->generateLinkToken();

            return [
                'plain' => $plain,
                'hash' => $this->hashToken($plain),
                'method' => $method->value,
                'activation_url' => '/activate#token='.$plain,
            ];
        }

        $plain = $this->generateTemporaryPassword();

        return [
            'plain' => $plain,
            'hash' => $this->hashPassword($plain),
            'method' => $method->value,
            'temporary_password' => $plain,
        ];
    }
}

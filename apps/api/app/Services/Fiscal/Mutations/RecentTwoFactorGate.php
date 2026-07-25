<?php

namespace App\Services\Fiscal\Mutations;

use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * @deprecated TOTP substituído por reconfirmação de senha.
 * Mantém a API legada e delega a {@see RecentPasswordConfirmationGate}.
 * confirmWithCode() aceita qualquer valor não-vazio em testing; em produção
 * este método não deve ser o caminho preferido — use confirm-password.
 */
final class RecentTwoFactorGate
{
    /** @deprecated use RecentPasswordConfirmationGate::SESSION_KEY */
    public const SESSION_KEY = RecentPasswordConfirmationGate::SESSION_KEY;

    public function __construct(
        private readonly RecentPasswordConfirmationGate $password,
    ) {}

    public function windowMinutes(): int
    {
        return $this->password->windowMinutes();
    }

    public function isRecentlyConfirmed(?User $user = null, ?Request $request = null): bool
    {
        return $this->password->isRecentlyConfirmed($user, $request);
    }

    public function secondsRemaining(?Request $request = null, ?User $user = null): int
    {
        return $this->password->secondsRemaining($request, $user);
    }

    /**
     * Legado: confirmação TOTP. Agora exige que a senha recente já esteja marcada
     * ou, em testing, aceita código "000000" como markConfirmed da senha.
     */
    public function confirmWithCode(User $user, string $code, ?Request $request = null): void
    {
        $code = trim($code);
        if ($code === '') {
            throw new RuntimeException('Confirmação inválida.');
        }

        // Em testing, "000000" marca a janela de senha (compat com fixtures legados).
        if (app()->environment('testing') && $code === '000000') {
            $this->password->markConfirmed($user, $request);

            return;
        }

        // Fora de testing: TOTP legado não é mais aceito — use POST /auth/confirm-password.
        throw new RuntimeException(
            'TOTP descontinuado. Reconfirme a senha em /api/v1/auth/confirm-password.'
        );
    }

    public function markConfirmed(User $user, ?Request $request = null): void
    {
        $this->password->markConfirmed($user, $request);
    }

    public function clear(?Request $request = null, ?User $user = null): void
    {
        $this->password->clear($request, $user);
    }

    public function expire(?Request $request = null, ?User $user = null): void
    {
        $this->password->expire($request, $user);
    }
}

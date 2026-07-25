<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Janela de reconfirmação de senha (15 min) vinculada à sessão corrente.
 * Cache, quando usado, é namespaced por session id — nunca por user id isolado
 * (evita que uma sessão autorize outra). Em testing sem sessão HTTP, usa chave
 * de teste isolada por user (suite feature sem cookie de sessão).
 */
final class RecentPasswordConfirmationGate
{
    public const SESSION_KEY = 'auth.password_confirmed_at';

    public const CONFIRMATION_METHOD = 'PASSWORD';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function windowMinutes(): int
    {
        return max(1, (int) config('auth.password_confirmation_window_minutes', 15));
    }

    public function isRecentlyConfirmed(?User $user = null, ?Request $request = null): bool
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        $confirmedAt = $this->confirmedAtTimestamp($request, $user);
        if ($confirmedAt === null) {
            return false;
        }

        $expires = $confirmedAt + ($this->windowMinutes() * 60);

        return time() < $expires;
    }

    public function secondsRemaining(?Request $request = null, ?User $user = null): int
    {
        $confirmedAt = $this->confirmedAtTimestamp($request, $user);
        if ($confirmedAt === null) {
            return 0;
        }

        $expires = $confirmedAt + ($this->windowMinutes() * 60);

        return max(0, $expires - time());
    }

    /**
     * @throws RuntimeException
     */
    public function confirmWithPassword(User $user, string $password, ?Request $request = null): void
    {
        $password = (string) $password;
        if ($password === '') {
            throw new RuntimeException('Senha obrigatória.');
        }

        if (! Hash::check($password, $user->password)) {
            $this->audit->record('auth.password_challenge', 'FAILURE', $user, [
                'reason' => 'invalid_password',
                'confirmation_method' => self::CONFIRMATION_METHOD,
            ], $user->id);

            throw new RuntimeException('Senha inválida.');
        }

        $this->markConfirmed($user, $request);
    }

    public function markConfirmed(User $user, ?Request $request = null): void
    {
        $now = time();
        $session = $this->session($request);
        if ($session !== null) {
            $session->put(self::SESSION_KEY, $now);
            $session->put(self::SESSION_KEY.'.user_id', $user->id);
        }

        $req = $request ?? request();
        $req?->attributes->set(self::SESSION_KEY, $now);

        Cache::put(
            $this->cacheKey($user, $request),
            $now,
            now()->addMinutes($this->windowMinutes() + 1),
        );

        $this->audit->record('auth.password_challenge', 'SUCCESS', $user, [
            'window_minutes' => $this->windowMinutes(),
            'confirmation_method' => self::CONFIRMATION_METHOD,
            'scope' => $session !== null ? 'session' : 'cache',
        ], $user->id);
    }

    public function clear(?Request $request = null, ?User $user = null): void
    {
        $session = $this->session($request);
        $session?->forget(self::SESSION_KEY);
        $session?->forget(self::SESSION_KEY.'.user_id');
        request()?->attributes->remove(self::SESSION_KEY);
        $user ??= auth()->user();
        if ($user instanceof User) {
            Cache::forget($this->cacheKey($user, $request));
            // Limpa chave de teste legada
            Cache::forget('auth.password_confirmed.'.$user->id.'.test');
        }
    }

    public function expire(?Request $request = null, ?User $user = null): void
    {
        $past = time() - (($this->windowMinutes() + 1) * 60);
        $session = $this->session($request);
        if ($session !== null) {
            $session->put(self::SESSION_KEY, $past);
            if ($user instanceof User) {
                $session->put(self::SESSION_KEY.'.user_id', $user->id);
            }
        }
        request()?->attributes->set(self::SESSION_KEY, $past);
        $user ??= auth()->user();
        if ($user instanceof User) {
            Cache::put(
                $this->cacheKey($user, $request),
                $past,
                now()->addMinutes($this->windowMinutes() + 1),
            );
        }
    }

    private function confirmedAtTimestamp(?Request $request = null, ?User $user = null): ?int
    {
        $user ??= auth()->user() instanceof User ? auth()->user() : null;

        $session = $this->session($request);
        if ($session !== null) {
            $value = $session->get(self::SESSION_KEY);
            $boundUserId = $session->get(self::SESSION_KEY.'.user_id');
            if (is_numeric($value)) {
                if ($user instanceof User && $boundUserId !== null && (int) $boundUserId !== (int) $user->id) {
                    return null;
                }

                return (int) $value;
            }
        }

        $attr = ($request ?? request())?->attributes->get(self::SESSION_KEY);
        if (is_numeric($attr)) {
            return (int) $attr;
        }

        if ($user instanceof User) {
            $cached = Cache::get($this->cacheKey($user, $request));
            if (is_numeric($cached)) {
                return (int) $cached;
            }
        }

        return null;
    }

    /**
     * Chave de cache: user + session id (não compartilha entre sessões).
     * Sem sessão em production: chave inválida (fail-closed).
     * Sem sessão em testing: chave .test por user (suite feature).
     */
    private function cacheKey(User $user, ?Request $request = null): string
    {
        $session = $this->session($request);
        $sid = $session?->getId();
        if (is_string($sid) && $sid !== '') {
            return 'auth.password_confirmed.'.$user->id.'.s.'.$sid;
        }

        if (app()->environment('testing')) {
            return 'auth.password_confirmed.'.$user->id.'.test';
        }

        // Fail-closed: sem sessão real não há confirmação persistente.
        return 'auth.password_confirmed.'.$user->id.'.invalid.'.uniqid('', true);
    }

    private function session(?Request $request = null): ?Session
    {
        $request ??= request();
        if ($request === null) {
            return null;
        }

        try {
            if ($request->hasSession()) {
                return $request->session();
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }
}

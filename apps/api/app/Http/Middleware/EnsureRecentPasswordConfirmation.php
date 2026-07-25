<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige reconfirmação recente de senha (15 min, sessão corrente) para ações sensíveis.
 * Aplica-se a todos os perfis (Plataforma e Escritório).
 *
 * Alias legado: EnsurePrivilegedPasswordConfirmation (mantido como subclass).
 */
class EnsureRecentPasswordConfirmation
{
    public function __construct(
        private readonly RecentPasswordConfirmationGate $passwordGate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        if ($this->passwordGate->isRecentlyConfirmed($user, $request)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Reconfirme sua senha para continuar esta ação.',
            'code' => 'password_confirmation_required',
            'window_minutes' => $this->passwordGate->windowMinutes(),
            'seconds_remaining' => $this->passwordGate->secondsRemaining($request, $user),
        ], 403);
    }
}

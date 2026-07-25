<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->is_active) {
            return $next($request);
        }

        try {
            app(RecentPasswordConfirmationGate::class)->clear(request(), $user instanceof User ? $user : null);
        } catch (\Throwable) {
            // best-effort
        }
        Auth::guard('web')->logout();
        Auth::forgetGuards();
        $request->setUserResolver(static fn () => null);

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Não autenticado.'], 401);
    }
}

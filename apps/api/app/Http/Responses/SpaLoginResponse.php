<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * SPA-only Fortify login response.
 *
 * Always returns JSON so the Nuxt Sanctum server proxy never follows a 302
 * to `/` on nginx (which 403s when the static SPA volume is empty/unavailable).
 */
class SpaLoginResponse implements LoginResponseContract
{
    public function toResponse($request): JsonResponse
    {
        return response()->json(['two_factor' => false]);
    }
}

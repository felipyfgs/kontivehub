<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

/**
 * SPA-only Fortify logout response (no redirect to web routes).
 */
class SpaLogoutResponse implements LogoutResponseContract
{
    public function toResponse($request): JsonResponse
    {
        return new JsonResponse(null, 204);
    }
}

<?php

namespace App\Http\Controllers\Internal;

use App\Actions\Communication\StreamGatewayMediaAction;
use App\Http\Controllers\Controller;
use App\Services\Communication\Security\HmacVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CommunicationGatewayMediaController extends Controller
{
    public function __invoke(
        Request $request,
        string $command,
        HmacVerifier $verifier,
        StreamGatewayMediaAction $action,
    ): StreamedResponse|JsonResponse {
        if (! config('communication.enabled') || ! config('communication.gateway.enabled')) {
            return response()->json(['error' => 'COMMUNICATION_DISABLED'], 503);
        }
        $verification = $verifier->verify(
            $request->method(),
            $request->getPathInfo(),
            '',
            $request->headers->all(),
        );
        if (! $verification->accepted()) {
            return response()->json(['error' => 'INVALID_INTERNAL_SIGNATURE'], 401);
        }

        return $action->execute($command)
            ?? response()->json(['error' => 'MEDIA_NOT_FOUND'], 404);
    }
}

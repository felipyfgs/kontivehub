<?php

namespace App\Http\Controllers\Internal;

use App\Actions\Communication\IngestCommunicationGatewayEventAction;
use App\Exceptions\GatewayEventConflictException;
use App\Http\Controllers\Controller;
use App\Services\Communication\Security\CommunicationHmacVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final class CommunicationGatewayEventController extends Controller
{
    public function __invoke(
        Request $request,
        CommunicationHmacVerifier $verifier,
        IngestCommunicationGatewayEventAction $action,
    ): Response|JsonResponse {
        if (! config('communication.enabled') || ! config('communication.gateway.enabled')) {
            return response()->json(['error' => 'COMMUNICATION_DISABLED'], 503);
        }

        $body = $request->getContent();
        $verification = $verifier->verify(
            $request->method(),
            $request->getPathInfo(),
            $body,
            $request->headers->all(),
        );
        if (! $verification->accepted()) {
            return response()->json(['error' => 'INVALID_INTERNAL_SIGNATURE'], 401);
        }

        try {
            $result = $action->execute($body);
        } catch (InvalidArgumentException) {
            return response()->json(['error' => 'INVALID_EVENT'], 422);
        } catch (GatewayEventConflictException) {
            return response()->json(['error' => 'EVENT_DIGEST_CONFLICT'], 409);
        }

        return response()->noContent(204, ['X-Communication-Result' => $result]);
    }
}

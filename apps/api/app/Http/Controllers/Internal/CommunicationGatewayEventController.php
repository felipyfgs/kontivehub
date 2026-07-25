<?php

namespace App\Http\Controllers\Internal;

use App\DTO\Communication\GatewayEventData;
use App\Enums\Communication\GatewayEventType;
use App\Exceptions\GatewayEventConflictException;
use App\Http\Controllers\Controller;
use App\Services\Communication\Events\GatewayEventIngestor;
use App\Services\Communication\Security\CommunicationHmacVerifier;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class CommunicationGatewayEventController extends Controller
{
    public function __invoke(
        Request $request,
        CommunicationHmacVerifier $verifier,
        GatewayEventIngestor $ingestor,
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
            $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return response()->json(['error' => 'INVALID_EVENT'], 422);
        }
        $validator = Validator::make(is_array($payload) ? $payload : [], [
            'contract_version' => ['required', 'in:v1'],
            'gateway_event_id' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/'],
            'session_id' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/'],
            'type' => ['required', 'string'],
            'occurred_at' => ['required', 'date'],
            'payload' => ['required', 'array'],
        ]);
        if ($validator->fails() || GatewayEventType::tryFrom((string) ($payload['type'] ?? '')) === null) {
            return response()->json(['error' => 'INVALID_EVENT'], 422);
        }

        try {
            $event = new GatewayEventData(
                gatewayEventId: (string) $payload['gateway_event_id'],
                sessionId: (string) $payload['session_id'],
                type: GatewayEventType::from((string) $payload['type']),
                occurredAt: new DateTimeImmutable((string) $payload['occurred_at']),
                payload: $payload['payload'],
            );
            $result = $ingestor->ingest($event);
        } catch (InvalidArgumentException) {
            return response()->json(['error' => 'INVALID_EVENT'], 422);
        } catch (GatewayEventConflictException) {
            return response()->json(['error' => 'EVENT_DIGEST_CONFLICT'], 409);
        }

        return response()->noContent(204, ['X-Communication-Result' => $result]);
    }
}

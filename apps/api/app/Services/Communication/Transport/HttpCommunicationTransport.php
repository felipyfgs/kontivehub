<?php

namespace App\Services\Communication\Transport;

use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayCommandData;
use App\DTO\Communication\GatewayCommandReceipt;
use App\DTO\Communication\GatewayContractPayload;
use App\DTO\Communication\GatewayQueryData;
use App\Enums\Communication\GatewayQueryType;
use App\Exceptions\CommunicationTransportException;
use App\Services\Communication\Gateway\GatewayQueryResultValidator;
use App\Services\Communication\Security\CommunicationHmacSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;

final readonly class HttpCommunicationTransport implements CommunicationTransport
{
    /** @var list<string> */
    private const SAFE_REMOTE_ERROR_CODES = [
        'BODY_TOO_LARGE',
        'COMMAND_DIGEST_CONFLICT',
        'COMMAND_PERSISTENCE_UNAVAILABLE',
        'GATEWAY_DISABLED',
        'INVALID_COMMAND',
        'INVALID_INTERNAL_SIGNATURE',
        'INVALID_QUERY',
        'MEDIA_NOT_FOUND',
        'MEDIA_SPOOL_UNAVAILABLE',
        'METRICS_UNAVAILABLE',
        'PROFILE_PICTURE_NOT_FOUND',
        'PROFILE_PICTURE_PRIVACY',
        'QUERY_EXECUTION_FAILED',
        'QUERY_EXECUTOR_UNAVAILABLE',
        'QUERY_TARGET_NOT_FOUND',
        'RECIPIENT_INVALID',
        'RECIPIENT_SCOPE_NOT_ALLOWED',
        'SESSION_STORE_UNAVAILABLE',
    ];

    public function __construct(
        private CommunicationHmacSigner $signer,
        private GatewayQueryResultValidator $queryResults,
    ) {}

    public function dispatch(GatewayCommandData $command): GatewayCommandReceipt
    {
        $this->assertEnabled();
        $path = '/internal/v1/commands';
        $body = json_encode($command->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        try {
            $response = Http::timeout($this->timeout())
                ->connectTimeout(min(5, $this->timeout()))
                ->acceptJson()
                ->withHeaders($this->signer->headers('POST', $path, $body))
                ->withBody($body, 'application/json')
                ->send('POST', $this->url($path));
        } catch (ConnectionException $error) {
            throw new CommunicationTransportException('GATEWAY_UNAVAILABLE', true, null);
        }

        $this->assertSuccessful($response, [202]);
        $payload = $response->json();
        if (! is_array($payload)
            || ($payload['command_id'] ?? null) !== $command->commandId
            || ($payload['status'] ?? null) !== 'ACCEPTED'
            || ! is_bool($payload['duplicate'] ?? null)) {
            throw new CommunicationTransportException('GATEWAY_INVALID_ACCEPTANCE', true, 202);
        }

        return new GatewayCommandReceipt($command->commandId, $payload['duplicate']);
    }

    public function query(GatewayQueryData $query): array
    {
        $this->assertEnabled();
        $path = '/internal/v1/queries';
        $body = json_encode($query->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timeout = $this->queryTimeout($query);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(min(5, $timeout))
                ->acceptJson()
                ->withHeaders($this->signer->headers('POST', $path, $body))
                ->withBody($body, 'application/json')
                ->send('POST', $this->url($path));
        } catch (ConnectionException) {
            throw new CommunicationTransportException('GATEWAY_UNAVAILABLE', true, null);
        }

        $this->assertSuccessful($response, [200]);
        $payload = $response->json();
        $result = is_array($payload) ? ($payload['result'] ?? null) : null;
        if (! is_array($payload)
            || array_diff(array_keys($payload), ['contract_version', 'query_id', 'result']) !== []
            || ($payload['contract_version'] ?? null) !== 'v1'
            || ($payload['query_id'] ?? null) !== $query->queryId
            || ! is_array($result)) {
            throw new CommunicationTransportException('GATEWAY_INVALID_QUERY_RESULT', true, 200);
        }

        try {
            GatewayContractPayload::assertSafePayload($payload);
            $this->queryResults->assertValid($query->type, $result);
        } catch (InvalidArgumentException) {
            throw new CommunicationTransportException('GATEWAY_UNSAFE_QUERY_RESULT', false, 200);
        }

        return $result;
    }

    public function sessionStatus(string $sessionId): array
    {
        $this->assertEnabled();
        $path = '/internal/v1/sessions/'.rawurlencode($sessionId);

        try {
            $response = Http::timeout($this->timeout())
                ->acceptJson()
                ->withHeaders($this->signer->headers('GET', $path))
                ->get($this->url($path));
        } catch (ConnectionException) {
            throw new CommunicationTransportException('GATEWAY_UNAVAILABLE', true, null);
        }
        $this->assertSuccessful($response, [200]);
        $payload = $response->json();
        if (! is_array($payload)
            || array_diff(array_keys($payload), [
                'session_id',
                'status',
                'desired_connected',
                'reconnect_count',
                'connected',
                'logged_in',
                'ready',
                'has_credentials',
            ]) !== []
            || ($payload['session_id'] ?? null) !== $sessionId
            || ! in_array($payload['status'] ?? null, [
                'DISCONNECTED',
                'CONNECTING',
                'CONNECTED',
            ], true)
            || ! is_bool($payload['desired_connected'] ?? null)
            || ! is_int($payload['reconnect_count'] ?? null)
            || $payload['reconnect_count'] < 0
            || ! is_bool($payload['connected'] ?? null)
            || ! is_bool($payload['logged_in'] ?? null)
            || ! is_bool($payload['ready'] ?? null)
            || ! is_bool($payload['has_credentials'] ?? null)) {
            throw new CommunicationTransportException('GATEWAY_INVALID_SESSION_STATUS', true, 200);
        }

        return [
            'session_id' => $sessionId,
            'status' => $payload['status'],
            'desired_connected' => $payload['desired_connected'],
            'reconnect_count' => $payload['reconnect_count'],
            'connected' => $payload['connected'],
            'logged_in' => $payload['logged_in'],
            'ready' => $payload['ready'],
            'has_credentials' => $payload['has_credentials'],
        ];
    }

    public function downloadMedia(string $spoolId): StreamInterface
    {
        $this->assertEnabled();
        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/', $spoolId)) {
            throw new CommunicationTransportException('INVALID_MEDIA_IDENTIFIER', false, null);
        }
        $path = '/internal/v1/media/'.rawurlencode($spoolId);

        try {
            $response = Http::timeout(max(30, $this->timeout()))
                ->withOptions(['stream' => true])
                ->withHeaders($this->signer->headers('GET', $path))
                ->get($this->url($path));
        } catch (ConnectionException) {
            throw new CommunicationTransportException('GATEWAY_MEDIA_UNAVAILABLE', true, null);
        }
        $this->assertSuccessful($response, [200]);

        return $response->toPsrResponse()->getBody();
    }

    private function assertEnabled(): void
    {
        if (! config('communication.enabled') || ! config('communication.gateway.enabled')) {
            throw new CommunicationTransportException('COMMUNICATION_DISABLED', false, 503);
        }
    }

    /** @param list<int> $expected */
    private function assertSuccessful(Response $response, array $expected): void
    {
        if (in_array($response->status(), $expected, true)) {
            return;
        }
        $status = $response->status();
        $remoteCode = $response->json('error');
        $code = is_string($remoteCode) && in_array($remoteCode, self::SAFE_REMOTE_ERROR_CODES, true)
            ? $remoteCode
            : 'GATEWAY_HTTP_'.$status;

        throw new CommunicationTransportException(
            $code,
            $status === 408 || $status === 425 || $status === 429 || $status >= 500,
            $status,
        );
    }

    private function timeout(): int
    {
        return max(1, (int) config('communication.gateway.timeout_seconds', 10));
    }

    private function queryTimeout(GatewayQueryData $query): int
    {
        if ($query->type !== GatewayQueryType::ProfilePicture) {
            return $this->timeout();
        }

        // Profile pictures can take up to 80 seconds in WhatsMeow; leave a
        // small margin to receive and validate the signed response.
        return min(90, max(16, (int) config('communication.profile_pictures.gateway_timeout_seconds', 90)));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('communication.gateway.base_url'), '/').$path;
    }
}

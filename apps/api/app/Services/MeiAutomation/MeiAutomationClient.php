<?php

namespace App\Services\MeiAutomation;

use App\DTO\MeiAutomation\MeiAutomationJobRequest;
use App\DTO\MeiAutomation\MeiAutomationJobResult;
use App\Exceptions\MeiAutomationTransportException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use RuntimeException;

final class MeiAutomationClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly MeiAutomationHmacSigner $signer,
        private readonly MeiAutomationInputPolicy $inputPolicy,
    ) {}

    public function create(MeiAutomationJobRequest $request): MeiAutomationJobResult
    {
        $payload = $request->toArray();
        $payload['input'] = $this->inputPolicy->sanitize($request->operationKey, $request->input);

        return $this->sendJson('POST', '/v1/jobs', $payload);
    }

    public function get(string $jobId): MeiAutomationJobResult
    {
        return $this->sendJson('GET', '/v1/jobs/'.$this->jobId($jobId));
    }

    public function cancel(string $jobId): MeiAutomationJobResult
    {
        return $this->sendJson('DELETE', '/v1/jobs/'.$this->jobId($jobId));
    }

    public function resume(string $jobId): MeiAutomationJobResult
    {
        return $this->sendJson('POST', '/v1/jobs/'.$this->jobId($jobId).'/resume');
    }

    public function downloadArtifact(string $jobId, string $artifactId): Response
    {
        $path = '/v1/jobs/'.$this->jobId($jobId).'/artifacts/'.$this->jobId($artifactId);
        try {
            $request = $this->http
                ->baseUrl(rtrim((string) config('mei_automation.base_url'), '/'))
                ->timeout(max(1, (int) config('mei_automation.timeout_seconds', 15)))
                ->withHeaders($this->signer->headers('GET', $path));
            $response = $request->get($path);
        } catch (ConnectionException) {
            throw new MeiAutomationTransportException('AUTOMATION_TRANSPORT_ERROR', 0);
        }
        $this->assertSuccessful($response);

        return $response;
    }

    /** @param array<string, mixed>|null $payload */
    private function sendJson(string $method, string $path, ?array $payload = null): MeiAutomationJobResult
    {
        $body = $payload === null
            ? ''
            : (string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $request = $this->http
            ->baseUrl(rtrim((string) config('mei_automation.base_url'), '/'))
            ->timeout(max(1, (int) config('mei_automation.timeout_seconds', 15)))
            ->acceptJson()
            ->withHeaders($this->signer->headers($method, $path, $body));

        if ($body !== '') {
            $request = $request->withBody($body, 'application/json');
        }

        try {
            $response = $request->send($method, $path);
        } catch (ConnectionException) {
            throw new MeiAutomationTransportException('AUTOMATION_TRANSPORT_ERROR', 0);
        }
        $this->assertSuccessful($response);

        $decoded = $response->json();
        if (! is_array($decoded)) {
            throw new RuntimeException('Resposta inválida da automação MEI.');
        }

        return MeiAutomationJobResult::fromArray($decoded);
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $code = match ($response->status()) {
            401 => 'AUTOMATION_AUTH_FAILED',
            404 => 'AUTOMATION_JOB_NOT_FOUND',
            409 => 'AUTOMATION_IDEMPOTENCY_CONFLICT',
            default => 'AUTOMATION_TRANSPORT_ERROR',
        };

        throw new MeiAutomationTransportException($code, $response->status());
    }

    private function jobId(string $jobId): string
    {
        if (! preg_match('/^[0-9a-f-]{36}$/i', $jobId)) {
            throw new RuntimeException('Identificador de job MEI inválido.');
        }

        return strtolower($jobId);
    }
}

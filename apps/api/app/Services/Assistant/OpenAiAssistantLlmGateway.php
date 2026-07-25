<?php

namespace App\Services\Assistant;

use App\Contracts\AssistantLlmGateway;
use GuzzleHttp\Client as GuzzleClient;
use OpenAI;
use OpenAI\Client;
use RuntimeException;

final class OpenAiAssistantLlmGateway implements AssistantLlmGateway
{
    public function __construct(
        private readonly AssistantAvailability $availability,
    ) {}

    public function complete(array $messages, array $tools): array
    {
        $this->availability->assertEnabled();

        $key = trim((string) config('assistant.openai.api_key'));
        if ($key === '') {
            throw new RuntimeException('OPENAI_API_KEY missing');
        }

        $payload = [
            'model' => (string) config('assistant.openai.model', 'gpt-4o-mini'),
            'messages' => $messages,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->client($key)->chat()->create($payload);

        $choice = $response->choices[0] ?? null;
        $message = $choice?->message;
        $toolCalls = [];
        foreach ($message?->toolCalls ?? [] as $call) {
            $rawArgs = $call->function->arguments ?? '{}';
            $decoded = json_decode($rawArgs, true);
            $toolCalls[] = [
                'id' => (string) $call->id,
                'name' => (string) $call->function->name,
                'arguments' => is_array($decoded) ? $decoded : [],
            ];
        }

        return [
            'content' => $message?->content,
            'tool_calls' => $toolCalls,
            'finish_reason' => (string) ($choice?->finishReason ?? 'stop'),
        ];
    }

    private function client(string $apiKey): Client
    {
        $timeout = max(1, (int) config('assistant.openai.timeout_seconds', 60));

        return OpenAI::factory()
            ->withApiKey($apiKey)
            ->withHttpClient(new GuzzleClient([
                'timeout' => $timeout,
            ]))
            ->make();
    }
}

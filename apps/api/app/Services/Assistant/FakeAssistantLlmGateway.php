<?php

namespace App\Services\Assistant;

use App\Contracts\AssistantLlmGateway;

/**
 * Fake determinístico para Feature/Unit tests (sem rede).
 */
final class FakeAssistantLlmGateway implements AssistantLlmGateway
{
    /**
     * @var list<array{
     *   content: ?string,
     *   tool_calls: list<array{id: string, name: string, arguments: array<string, mixed>}>,
     *   finish_reason: string
     * }>
     */
    private array $queue = [];

    private int $calls = 0;

    /**
     * @param  array{
     *   content?: ?string,
     *   tool_calls?: list<array{id?: string, name: string, arguments?: array<string, mixed>}>,
     *   finish_reason?: string
     * }  $response
     */
    public function enqueue(array $response): void
    {
        $toolCalls = [];
        foreach ($response['tool_calls'] ?? [] as $i => $call) {
            $toolCalls[] = [
                'id' => $call['id'] ?? ('call_fake_'.$i),
                'name' => $call['name'],
                'arguments' => $call['arguments'] ?? [],
            ];
        }

        $this->queue[] = [
            'content' => $response['content'] ?? null,
            'tool_calls' => $toolCalls,
            'finish_reason' => $response['finish_reason'] ?? (count($toolCalls) > 0 ? 'tool_calls' : 'stop'),
        ];
    }

    public function complete(array $messages, array $tools): array
    {
        $this->calls++;
        if ($this->queue === []) {
            return [
                'content' => 'Resposta fake do assistente.',
                'tool_calls' => [],
                'finish_reason' => 'stop',
            ];
        }

        return array_shift($this->queue);
    }

    public function callCount(): int
    {
        return $this->calls;
    }
}

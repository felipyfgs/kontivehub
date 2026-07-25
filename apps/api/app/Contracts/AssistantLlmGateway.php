<?php

namespace App\Contracts;

/**
 * Gateway LLM do assistente — permite fake em testes sem rede.
 */
interface AssistantLlmGateway
{
    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $tools
     * @return array{
     *   content: ?string,
     *   tool_calls: list<array{id: string, name: string, arguments: array<string, mixed>}>,
     *   finish_reason: string
     * }
     */
    public function complete(array $messages, array $tools): array;
}

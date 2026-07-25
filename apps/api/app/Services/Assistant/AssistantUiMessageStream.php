<?php

namespace App\Services\Assistant;

/**
 * Formata eventos no estilo UI Message Stream (Vercel AI SDK) via SSE.
 *
 * Protocolo documentado para o cliente Nuxt (@ai-sdk/vue):
 * - Content-Type: text/event-stream
 * - Header: X-Vercel-AI-UI-Message-Stream: v1
 * - Cada evento: `data: {json}\n\n`
 * - Encerramento: `data: [DONE]\n\n`
 *
 * Tipos emitidos:
 * - start / text-start / text-delta / text-end / finish
 * - tool-input-available (preview da tool)
 * - data-assistant-approval (token de approval para create_process_template)
 */
final class AssistantUiMessageStream
{
    /**
     * @param  array{
     *   assistant_text: ?string,
     *   pending_approvals: list<array{approval_token: string, tool_name: string, tool_call_id: string, args: array<string, mixed>}>,
     *   message_id?: string
     * }  $turn
     * @return \Generator<int, string>
     */
    public function encode(array $turn): \Generator
    {
        $messageId = $turn['message_id'] ?? ('msg_'.bin2hex(random_bytes(8)));
        yield $this->event(['type' => 'start', 'messageId' => $messageId]);

        $text = (string) ($turn['assistant_text'] ?? '');
        if ($text !== '') {
            $textId = 'text_'.$messageId;
            yield $this->event(['type' => 'text-start', 'id' => $textId]);
            yield $this->event(['type' => 'text-delta', 'id' => $textId, 'delta' => $text]);
            yield $this->event(['type' => 'text-end', 'id' => $textId]);
        }

        foreach ($turn['pending_approvals'] as $approval) {
            yield $this->event([
                'type' => 'tool-input-available',
                'toolCallId' => $approval['tool_call_id'],
                'toolName' => $approval['tool_name'],
                'input' => $approval['args'],
            ]);
            yield $this->event([
                'type' => 'data-assistant-approval',
                'data' => [
                    'approval_token' => $approval['approval_token'],
                    'tool_name' => $approval['tool_name'],
                    'tool_call_id' => $approval['tool_call_id'],
                    'args' => $approval['args'],
                ],
            ]);
        }

        yield $this->event(['type' => 'finish']);
        yield "data: [DONE]\n\n";
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(array $payload): string
    {
        return 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
    }
}

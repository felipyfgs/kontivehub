<?php

namespace App\Services\Assistant;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class AssistantPendingApprovalStore
{
    private const TTL_SECONDS = 1800;

    /**
     * @param  array<string, mixed>  $args
     */
    public function put(
        int $tenantId,
        int $conversationId,
        string $toolCallId,
        string $toolName,
        array $args,
    ): string {
        $token = (string) Str::uuid();
        Cache::put($this->key($token), [
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'tool_call_id' => $toolCallId,
            'tool_name' => $toolName,
            'args' => $this->sanitizeArgs($args),
            'created_at' => now()->toIso8601String(),
        ], self::TTL_SECONDS);

        return $token;
    }

    /**
     * @return array{tenant_id: int, conversation_id: int, tool_call_id: string, tool_name: string, args: array<string, mixed>, created_at: string}|null
     */
    public function pull(string $token, int $tenantId, int $conversationId): ?array
    {
        $key = $this->key($token);
        $payload = Cache::pull($key);
        if (! is_array($payload)) {
            return null;
        }

        if ((int) ($payload['tenant_id'] ?? 0) !== $tenantId
            || (int) ($payload['conversation_id'] ?? 0) !== $conversationId) {
            return null;
        }

        /** @var array{tenant_id: int, conversation_id: int, tool_call_id: string, tool_name: string, args: array<string, mixed>, created_at: string} $payload */
        return $payload;
    }

    /**
     * Invalida pending approval com bind tenant/conversa (sem consumir token alheio).
     */
    public function forget(string $token, int $tenantId, int $conversationId): bool
    {
        $key = $this->key($token);
        $payload = Cache::get($key);
        if (! is_array($payload)) {
            return false;
        }

        if ((int) ($payload['tenant_id'] ?? 0) !== $tenantId
            || (int) ($payload['conversation_id'] ?? 0) !== $conversationId) {
            return false;
        }

        return Cache::forget($key);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function sanitizeArgs(array $args): array
    {
        unset($args['tenant_id'], $args['api_key'], $args['openai_api_key']);

        return $args;
    }

    private function key(string $token): string
    {
        return 'assistant:pending_approval:'.$token;
    }
}

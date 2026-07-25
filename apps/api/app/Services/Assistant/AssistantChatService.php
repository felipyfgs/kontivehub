<?php

namespace App\Services\Assistant;

use App\Contracts\AssistantLlmGateway;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\User;
use App\Support\CurrentOffice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class AssistantChatService
{
    private const MAX_TOOL_LOOPS = 4;

    public function __construct(
        private readonly AssistantAvailability $availability,
        private readonly AssistantLlmGateway $llm,
        private readonly AssistantToolRegistry $tools,
        private readonly AssistantPendingApprovalStore $approvals,
        private readonly CurrentOffice $currentOffice,
    ) {}

    /**
     * @return array{
     *   assistant_text: ?string,
     *   messages: list<AssistantMessage>,
     *   pending_approvals: list<array{approval_token: string, tool_name: string, tool_call_id: string, args: array<string, mixed>}>,
     *   tool_results: list<array<string, mixed>>
     * }
     */
    public function chat(AssistantConversation $conversation, User $user, string $content): array
    {
        $this->availability->assertEnabled();
        $this->assertConversationScope($conversation, $user);

        $officeId = (int) $this->currentOffice->id();
        $userMessage = AssistantMessage::query()->create([
            'office_id' => $officeId,
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $content,
        ]);

        if ($conversation->title === null || $conversation->title === '') {
            $conversation->forceFill([
                'title' => mb_substr(trim($content), 0, 80) ?: 'Conversa',
            ])->save();
        }

        $llmMessages = $this->buildLlmMessages($conversation);
        $pendingApprovals = [];
        $collectedToolResults = [];
        $assistantText = null;
        $assistantToolCalls = [];
        $createdMessages = [$userMessage];

        for ($loop = 0; $loop < self::MAX_TOOL_LOOPS; $loop++) {
            $completion = $this->llm->complete($llmMessages, $this->tools->openAiTools());
            $assistantText = $completion['content'];
            $toolCalls = $completion['tool_calls'];

            if ($toolCalls === []) {
                break;
            }

            $assistantToolCalls = array_merge($assistantToolCalls, $this->sanitizeToolCalls($toolCalls));
            $llmMessages[] = [
                'role' => 'assistant',
                'content' => $assistantText,
                'tool_calls' => array_map(fn (array $c) => [
                    'id' => $c['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $c['name'],
                        'arguments' => json_encode($c['arguments'], JSON_UNESCAPED_UNICODE),
                    ],
                ], $toolCalls),
            ];

            $continueLoop = false;
            foreach ($toolCalls as $call) {
                if (! $this->tools->isAllowlisted($call['name'])) {
                    $payload = [
                        'status' => 'rejected',
                        'error' => 'ASSISTANT_TOOL_UNKNOWN',
                    ];
                    $collectedToolResults[] = [
                        'tool_call_id' => $call['id'],
                        'tool_name' => $call['name'],
                        ...$payload,
                    ];
                    $llmMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $call['id'],
                        'content' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    ];

                    continue;
                }

                if ($call['name'] === AssistantToolRegistry::CREATE_PROCESS_TEMPLATE) {
                    $result = $this->tools->execute(
                        $call['name'],
                        $call['arguments'],
                        $user,
                        approved: false,
                        conversationId: $conversation->id,
                        toolCallId: $call['id'],
                    );
                    $collectedToolResults[] = [
                        'tool_call_id' => $call['id'],
                        'tool_name' => $call['name'],
                        ...$result,
                    ];
                    if (($result['status'] ?? null) === 'pending_approval' && isset($result['approval_token'])) {
                        $pendingApprovals[] = [
                            'approval_token' => $result['approval_token'],
                            'tool_name' => $call['name'],
                            'tool_call_id' => $call['id'],
                            'args' => $result['args'] ?? $call['arguments'],
                        ];
                    }
                    $llmMessages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $call['id'],
                        'content' => json_encode([
                            'status' => 'pending_approval',
                            'message' => 'Aguardando confirmação explícita do usuário na UI.',
                        ], JSON_UNESCAPED_UNICODE),
                    ];

                    continue;
                }

                $result = $this->tools->execute($call['name'], $call['arguments'], $user);
                $collectedToolResults[] = [
                    'tool_call_id' => $call['id'],
                    'tool_name' => $call['name'],
                    ...$result,
                ];
                $llmMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $call['id'],
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
                $continueLoop = true;
            }

            if ($pendingApprovals !== [] || ! $continueLoop) {
                break;
            }
        }

        if ($assistantText === null && $pendingApprovals !== []) {
            $assistantText = 'Proposta de criação de modelo de processo pronta para confirmação.';
        }

        $assistantMessage = AssistantMessage::query()->create([
            'office_id' => $officeId,
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $assistantText,
            'tool_calls' => $assistantToolCalls !== [] ? $assistantToolCalls : null,
            'tool_results' => $collectedToolResults !== [] ? $this->sanitizeToolResults($collectedToolResults) : null,
        ]);
        $createdMessages[] = $assistantMessage;
        $conversation->touch();

        return [
            'assistant_text' => $assistantText,
            'messages' => $createdMessages,
            'pending_approvals' => $pendingApprovals,
            'tool_results' => $collectedToolResults,
        ];
    }

    /**
     * @return array{status: string, result?: mixed, error?: string, message?: AssistantMessage}
     */
    public function approveTool(
        AssistantConversation $conversation,
        User $user,
        string $approvalToken,
    ): array {
        $this->availability->assertEnabled();
        $this->assertConversationScope($conversation, $user);

        try {
            $outcome = $this->tools->execute(
                AssistantToolRegistry::CREATE_PROCESS_TEMPLATE,
                [],
                $user,
                approved: true,
                approvalToken: $approvalToken,
                conversationId: $conversation->id,
            );
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        }

        $officeId = (int) $this->currentOffice->id();
        $message = AssistantMessage::query()->create([
            'office_id' => $officeId,
            'conversation_id' => $conversation->id,
            'role' => 'tool',
            'content' => json_encode($outcome, JSON_UNESCAPED_UNICODE),
            'tool_results' => [$this->sanitizeToolResults([$outcome])[0] ?? $outcome],
        ]);
        $conversation->touch();

        return [
            ...$outcome,
            'message' => $message,
        ];
    }

    /**
     * @return array{status: string, error?: string, message?: AssistantMessage}
     */
    public function denyTool(
        AssistantConversation $conversation,
        User $user,
        string $approvalToken,
    ): array {
        $this->availability->assertEnabled();
        $this->assertConversationScope($conversation, $user);

        $officeId = (int) $this->currentOffice->id();
        $forgotten = $this->approvals->forget(
            $approvalToken,
            $officeId,
            $conversation->id,
        );

        if (! $forgotten) {
            return [
                'status' => 'rejected',
                'error' => 'APPROVAL_INVALID',
            ];
        }

        $outcome = ['status' => 'denied'];
        $message = AssistantMessage::query()->create([
            'office_id' => $officeId,
            'conversation_id' => $conversation->id,
            'role' => 'tool',
            'content' => json_encode($outcome, JSON_UNESCAPED_UNICODE),
            'tool_results' => [$this->sanitizeToolResults([$outcome])[0] ?? $outcome],
        ]);
        $conversation->touch();

        return [
            'status' => 'denied',
            'message' => $message,
        ];
    }

    private function assertConversationScope(AssistantConversation $conversation, User $user): void
    {
        $officeId = $this->currentOffice->id();
        if ($officeId === null || (int) $conversation->office_id !== (int) $officeId) {
            abort(404);
        }
        if ((int) $conversation->user_id !== (int) $user->id) {
            abort(404);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildLlmMessages(AssistantConversation $conversation): array
    {
        $messages = [
            [
                'role' => 'system',
                'content' => (string) config('assistant.system_prompt'),
            ],
        ];

        $history = AssistantMessage::query()
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->limit(40)
            ->get();

        foreach ($history as $msg) {
            if ($msg->role === 'tool') {
                continue;
            }
            if ($msg->role === 'assistant' && is_array($msg->tool_calls) && $msg->tool_calls !== []) {
                $messages[] = [
                    'role' => 'assistant',
                    'content' => $msg->content,
                    'tool_calls' => array_map(fn (array $c) => [
                        'id' => $c['id'] ?? ('call_'.uniqid()),
                        'type' => 'function',
                        'function' => [
                            'name' => $c['name'] ?? 'unknown',
                            'arguments' => json_encode($c['arguments'] ?? [], JSON_UNESCAPED_UNICODE),
                        ],
                    ], $msg->tool_calls),
                ];
                if (is_array($msg->tool_results)) {
                    foreach ($msg->tool_results as $result) {
                        $messages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $result['tool_call_id'] ?? 'unknown',
                            'content' => json_encode([
                                'status' => $result['status'] ?? null,
                                'result' => $result['result'] ?? null,
                                'error' => $result['error'] ?? null,
                            ], JSON_UNESCAPED_UNICODE),
                        ];
                    }
                }

                continue;
            }

            $messages[] = [
                'role' => in_array($msg->role, ['user', 'assistant', 'system'], true) ? $msg->role : 'user',
                'content' => (string) ($msg->content ?? ''),
            ];
        }

        return $messages;
    }

    /**
     * @param  list<array{id: string, name: string, arguments: array<string, mixed>}>  $toolCalls
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    private function sanitizeToolCalls(array $toolCalls): array
    {
        return array_map(fn (array $c) => [
            'id' => $c['id'],
            'name' => $c['name'],
            'arguments' => $this->stripSecrets($c['arguments']),
        ], $toolCalls);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    private function sanitizeToolResults(array $results): array
    {
        return array_map(function (array $r): array {
            if (is_array($r['args'] ?? null)) {
                unset($r['args']['office_id'], $r['args']['api_key'], $r['args']['openai_api_key']);
            }
            unset($r['approval_token']);

            return $this->stripSecrets($r);
        }, $results);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stripSecrets(array $payload): array
    {
        foreach (['api_key', 'openai_api_key', 'OPENAI_API_KEY', 'authorization', 'approval_token'] as $secret) {
            unset($payload[$secret]);
        }

        return $payload;
    }
}

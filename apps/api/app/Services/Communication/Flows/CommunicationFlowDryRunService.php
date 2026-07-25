<?php

namespace App\Services\Communication\Flows;

use App\Models\CommunicationCannedResponse;

/**
 * Simulação in-memory do caminhar do grafo sem outbox, jobs, FlowRun ou egress.
 */
final class CommunicationFlowDryRunService
{
    private const MAX_STEPS = 64;

    public function __construct(
        private readonly CommunicationFlowGraphValidator $validator,
        private readonly CommunicationFlowGraphCanonicalizer $canonicalizer,
        private readonly CommunicationFlowTextMasker $masker,
    ) {}

    /**
     * @param  array{nodes?: list<mixed>, edges?: list<mixed>}  $graph
     * @param  array{
     *   contact_name?: string,
     *   conversation_status?: string,
     *   last_inbound_text?: string,
     *   question_answers?: array<string, string>
     * }  $context
     */
    public function simulate(array $graph, int $officeId, array $context = []): CommunicationFlowDryRunResult
    {
        $validation = $this->validator->validate($graph, $officeId);
        if (! $validation->valid) {
            return CommunicationFlowDryRunResult::invalid($validation->digest, $validation->errors);
        }

        $digest = $validation->digest !== ''
            ? $validation->digest
            : $this->canonicalizer->digest($graph);

        /** @var list<array<string, mixed>> $nodes */
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        /** @var list<array<string, mixed>> $edges */
        $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];

        $nodeMap = [];
        $startId = null;
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $id = isset($node['id']) && is_string($node['id']) ? $node['id'] : '';
            if ($id === '') {
                continue;
            }
            $nodeMap[$id] = $node;
            if (strtolower((string) ($node['type'] ?? '')) === 'start') {
                $startId = $id;
            }
        }

        if ($startId === null) {
            return CommunicationFlowDryRunResult::invalid($digest, [[
                'path' => 'graph.nodes',
                'code' => 'start_required',
                'message' => 'O grafo exige exatamente um nó start.',
            ]]);
        }

        $answers = [];
        if (isset($context['question_answers']) && is_array($context['question_answers'])) {
            foreach ($context['question_answers'] as $nodeId => $answer) {
                if (is_string($nodeId) && is_string($answer)) {
                    $answers[$nodeId] = $answer;
                }
            }
        }

        $steps = [];
        $current = $startId;
        $seq = 0;
        $outcome = 'running';
        $finished = false;

        for ($guard = 0; $guard < self::MAX_STEPS; $guard++) {
            $node = $nodeMap[$current] ?? null;
            if ($node === null) {
                $steps[] = $this->step(++$seq, $current, 'unknown', 'failed', ['code' => 'missing_node']);
                $outcome = 'failed';
                $finished = true;

                break;
            }

            $type = strtolower((string) ($node['type'] ?? ''));
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];

            if ($type === 'start') {
                $steps[] = $this->step(++$seq, $current, $type, 'completed', ['phase' => 'start']);
                $next = $this->nextNodeId($edges, $current);
                if ($next === null) {
                    $outcome = 'completed';
                    $finished = true;

                    break;
                }
                $current = $next;

                continue;
            }

            if ($type === 'end') {
                $steps[] = $this->step(++$seq, $current, $type, 'completed', ['phase' => 'end']);
                $outcome = 'completed';
                $finished = true;

                break;
            }

            if ($type === 'handoff') {
                $steps[] = $this->step(++$seq, $current, $type, 'completed', [
                    'phase' => 'handoff',
                    'assignee_membership_id' => isset($data['assignee_membership_id'])
                        ? (int) $data['assignee_membership_id']
                        : null,
                ]);
                $outcome = 'handed_off';
                $finished = true;

                break;
            }

            if ($type === 'condition') {
                $branch = $this->evaluateCondition($data, $context) ? 'true' : 'false';
                $steps[] = $this->step(++$seq, $current, $type, 'completed', ['branch' => $branch]);
                $next = $this->nextNodeId($edges, $current, $branch);
                if ($next === null) {
                    $outcome = 'completed';
                    $finished = true;

                    break;
                }
                $current = $next;

                continue;
            }

            if ($type === 'delay') {
                $seconds = max(1, (int) ($data['duration_seconds'] ?? 1));
                $steps[] = $this->step(++$seq, $current, $type, 'simulated_delay', [
                    'duration_seconds' => $seconds,
                    'elapsed' => true,
                ]);
                $next = $this->nextNodeId($edges, $current);
                if ($next === null) {
                    $outcome = 'completed';
                    $finished = true;

                    break;
                }
                $current = $next;

                continue;
            }

            if ($type === 'action') {
                $steps[] = $this->step(++$seq, $current, $type, 'simulated_action', [
                    'kind' => (string) ($data['kind'] ?? ''),
                ]);
                $next = $this->nextNodeId($edges, $current);
                if ($next === null) {
                    $outcome = 'completed';
                    $finished = true;

                    break;
                }
                $current = $next;

                continue;
            }

            if (in_array($type, ['message', 'quick_reply'], true)) {
                $body = $this->resolveMessageBody($type, $data, $officeId);
                $steps[] = $this->step(++$seq, $current, $type, 'simulated_send', [
                    'body_preview' => $this->masker->preview($body),
                    'body_digest' => hash('sha256', $body),
                    'egress' => false,
                ]);
                $next = $this->nextNodeId($edges, $current);
                if ($next === null) {
                    $outcome = 'completed';
                    $finished = true;

                    break;
                }
                $current = $next;

                continue;
            }

            if ($type === 'question') {
                $prompt = trim((string) ($data['prompt'] ?? ''));
                $answer = $answers[$current] ?? null;
                if ($answer === null || trim($answer) === '') {
                    $steps[] = $this->step(++$seq, $current, $type, 'waiting_input', [
                        'prompt_preview' => $this->masker->preview($prompt),
                        'egress' => false,
                    ]);
                    $outcome = 'waiting_input';
                    $finished = true;

                    break;
                }
                $branch = $this->matchQuestionBranch($data, $answer);
                $steps[] = $this->step(++$seq, $current, $type, 'completed', [
                    'prompt_preview' => $this->masker->preview($prompt),
                    'answer_preview' => $this->masker->preview($answer),
                    'branch' => $branch,
                    'egress' => false,
                ]);
                $next = $this->nextNodeId($edges, $current, $branch);
                if ($next === null) {
                    $next = $this->nextNodeId($edges, $current);
                }
                if ($next === null) {
                    $outcome = 'completed';
                    $finished = true;

                    break;
                }
                $current = $next;

                continue;
            }

            $steps[] = $this->step(++$seq, $current, $type !== '' ? $type : 'unknown', 'failed', [
                'code' => 'unsupported_node_type',
            ]);
            $outcome = 'failed';
            $finished = true;

            break;
        }

        if (! $finished) {
            $outcome = 'truncated';
            $steps[] = $this->step(++$seq, $current, (string) ($nodeMap[$current]['type'] ?? 'unknown'), 'failed', [
                'code' => 'max_steps_exceeded',
            ]);
        }

        return CommunicationFlowDryRunResult::ok($digest, $outcome, $steps);
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array{seq: int, node_id: string, node_type: string, status: string, detail: array<string, mixed>}
     */
    private function step(int $seq, string $nodeId, string $type, string $status, array $detail): array
    {
        return [
            'seq' => $seq,
            'node_id' => $nodeId,
            'node_type' => $type,
            'status' => $status,
            'detail' => $detail,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     */
    private function evaluateCondition(array $data, array $context): bool
    {
        $field = (string) ($data['field'] ?? '');
        $operator = strtolower((string) ($data['operator'] ?? 'eq'));
        $expected = $data['value'] ?? null;
        $actual = match ($field) {
            'contact.name' => (string) ($context['contact_name'] ?? ''),
            'conversation.status' => (string) ($context['conversation_status'] ?? ''),
            'last_inbound_text' => (string) ($context['last_inbound_text'] ?? ''),
            default => '',
        };
        $expectedStr = is_bool($expected) ? ($expected ? 'true' : 'false') : (string) $expected;
        if ($operator === 'contains') {
            return $expectedStr !== '' && str_contains(mb_strtolower($actual), mb_strtolower($expectedStr));
        }

        return mb_strtolower(trim($actual)) === mb_strtolower(trim($expectedStr));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function matchQuestionBranch(array $data, string $answer): ?string
    {
        $options = is_array($data['options'] ?? null) ? $data['options'] : [];
        $normalized = mb_strtolower(trim($answer));
        foreach ($options as $option) {
            if (! is_string($option)) {
                continue;
            }
            if (mb_strtolower(trim($option)) === $normalized) {
                return trim($option);
            }
        }

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveMessageBody(string $type, array $data, int $officeId): string
    {
        if ($type === 'quick_reply' || isset($data['canned_response_id'])) {
            $cannedId = (int) ($data['canned_response_id'] ?? 0);
            if ($cannedId > 0) {
                $canned = CommunicationCannedResponse::query()
                    ->withoutGlobalScopes()
                    ->where('office_id', $officeId)
                    ->whereKey($cannedId)
                    ->first();
                if ($canned !== null) {
                    return trim((string) ($canned->body_encrypted ?? ''));
                }
            }
        }

        return trim((string) ($data['body'] ?? ''));
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     */
    private function nextNodeId(array $edges, string $sourceId, ?string $branch = null): ?string
    {
        $candidates = [];
        foreach ($edges as $edge) {
            if (! is_array($edge) || ($edge['source'] ?? null) !== $sourceId) {
                continue;
            }
            $label = $this->edgeBranch($edge);
            $candidates[] = ['target' => (string) ($edge['target'] ?? ''), 'label' => $label];
        }
        if ($candidates === []) {
            return null;
        }
        if ($branch !== null) {
            foreach ($candidates as $candidate) {
                if ($candidate['label'] !== null
                    && mb_strtolower($candidate['label']) === mb_strtolower($branch)
                    && $candidate['target'] !== '') {
                    return $candidate['target'];
                }
            }
        }
        foreach ($candidates as $candidate) {
            if ($candidate['target'] !== '') {
                return $candidate['target'];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $edge */
    private function edgeBranch(array $edge): ?string
    {
        foreach (['label', 'branch', 'sourceHandle'] as $key) {
            if (isset($edge[$key]) && is_string($edge[$key]) && trim($edge[$key]) !== '') {
                return trim($edge[$key]);
            }
        }
        $data = is_array($edge['data'] ?? null) ? $edge['data'] : [];
        if (isset($data['branch']) && is_string($data['branch']) && trim($data['branch']) !== '') {
            return trim($data['branch']);
        }

        return null;
    }
}

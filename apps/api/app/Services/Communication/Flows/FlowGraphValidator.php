<?php

namespace App\Services\Communication\Flows;

use App\Enums\Communication\ConversationStatus;
use App\Models\CommunicationCannedResponse;
use App\Models\CommunicationLabel;
use App\Models\TenantMembership;

final class FlowGraphValidator
{
    public const ALLOWED_TYPES = [
        'start',
        'message',
        'quick_reply',
        'question',
        'condition',
        'delay',
        'action',
        'handoff',
        'end',
    ];

    private const FORBIDDEN_TYPE_HINTS = [
        'webhook',
        'code',
        'script',
        'ai',
        'llm',
        'regex',
        'jsonpath',
        'http',
        'fiscal',
        'serpro',
        'callback',
    ];

    private const CONDITION_OPERATORS = ['eq', 'contains'];

    private const CONDITION_FIELDS = [
        'contact.name',
        'conversation.status',
        'last_inbound_text',
    ];

    private const ACTION_KINDS = ['label', 'assignee', 'status'];

    public function __construct(
        private readonly FlowGraphCanonicalizer $canonicalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $graph
     */
    public function validate(array $graph, int $tenantId): FlowGraphValidationResult
    {
        $digest = $this->canonicalizer->digest($graph);
        $errors = [];

        $this->rejectForbiddenPayload($graph, 'graph', $errors);

        if (! isset($graph['nodes']) || ! is_array($graph['nodes']) || ! array_is_list($graph['nodes'])) {
            $errors[] = $this->error('graph.nodes', 'nodes_required', 'O grafo exige nodes[] como lista.');
        }
        if (! isset($graph['edges']) || ! is_array($graph['edges']) || ! array_is_list($graph['edges'])) {
            $errors[] = $this->error('graph.edges', 'edges_required', 'O grafo exige edges[] como lista.');
        }
        if ($errors !== []) {
            return FlowGraphValidationResult::invalid($digest, $errors);
        }

        /** @var list<array<string, mixed>> $nodes */
        $nodes = $graph['nodes'];
        /** @var list<array<string, mixed>> $edges */
        $edges = $graph['edges'];

        $nodeIds = [];
        $startIds = [];
        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                $errors[] = $this->error("graph.nodes.$index", 'node_invalid', 'Nó inválido.');

                continue;
            }
            $path = "graph.nodes.$index";
            $id = isset($node['id']) && is_string($node['id']) ? trim($node['id']) : '';
            $type = isset($node['type']) && is_string($node['type']) ? strtolower(trim($node['type'])) : '';
            if ($id === '') {
                $errors[] = $this->error("$path.id", 'node_id_required', 'Cada nó exige id.');
            } elseif (isset($nodeIds[$id])) {
                $errors[] = $this->error("$path.id", 'node_id_duplicate', "Id de nó duplicado: {$id}.");
            } else {
                $nodeIds[$id] = $type;
            }
            if ($type === '' || ! in_array($type, self::ALLOWED_TYPES, true)) {
                $errors[] = $this->error("$path.type", 'node_type_forbidden', "Tipo de nó não permitido: {$type}.");
            }
            if ($type === 'start') {
                $startIds[] = $id;
            }
            $data = is_array($node['data'] ?? null) ? $node['data'] : [];
            $this->rejectForbiddenPayload($data, "$path.data", $errors);
            if (in_array($type, self::ALLOWED_TYPES, true)) {
                $this->validateNodeData($type, $data, $tenantId, $path, $errors);
            }
        }

        if (count($startIds) !== 1) {
            $errors[] = $this->error('graph.nodes', 'start_required', 'O grafo exige exatamente um nó start.');
        }

        $adjacency = [];
        foreach (array_keys($nodeIds) as $id) {
            $adjacency[$id] = [];
        }
        foreach ($edges as $index => $edge) {
            if (! is_array($edge)) {
                $errors[] = $this->error("graph.edges.$index", 'edge_invalid', 'Aresta inválida.');

                continue;
            }
            $path = "graph.edges.$index";
            $source = isset($edge['source']) && is_string($edge['source']) ? trim($edge['source']) : '';
            $target = isset($edge['target']) && is_string($edge['target']) ? trim($edge['target']) : '';
            if ($source === '' || $target === '') {
                $errors[] = $this->error($path, 'edge_endpoints_required', 'Aresta exige source e target.');

                continue;
            }
            if (! isset($nodeIds[$source]) || ! isset($nodeIds[$target])) {
                $errors[] = $this->error($path, 'edge_unknown_node', 'Aresta aponta para nó inexistente.');

                continue;
            }
            $adjacency[$source][] = $target;
        }

        if (count($startIds) === 1 && $startIds[0] !== '' && isset($nodeIds[$startIds[0]])) {
            $reachable = $this->reachableFrom($startIds[0], $adjacency);
            foreach (array_keys($nodeIds) as $id) {
                if (! isset($reachable[$id])) {
                    $errors[] = $this->error('graph.nodes', 'orphan_node', "Nó órfão não alcançável a partir do start: {$id}.");
                }
            }
            if ($this->hasCycle($adjacency, $reachable)) {
                $errors[] = $this->error('graph.edges', 'cycle_detected', 'O grafo contém ciclo; apenas DAG é permitido.');
            }
        }

        if ($errors !== []) {
            return FlowGraphValidationResult::invalid($digest, $errors);
        }

        return FlowGraphValidationResult::ok($digest);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{path: string, code: string, message: string}>  $errors
     */
    private function validateNodeData(string $type, array $data, int $tenantId, string $path, array &$errors): void
    {
        match ($type) {
            'start', 'end' => null,
            'message' => $this->validateMessage($data, $tenantId, $path, $errors),
            'quick_reply' => $this->validateQuickReply($data, $tenantId, $path, $errors),
            'question' => $this->validateQuestion($data, $path, $errors),
            'condition' => $this->validateCondition($data, $path, $errors),
            'delay' => $this->validateDelay($data, $path, $errors),
            'action' => $this->validateAction($data, $tenantId, $path, $errors),
            'handoff' => $this->validateHandoff($data, $tenantId, $path, $errors),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{path: string, code: string, message: string}>  $errors
     */
    private function validateMessage(array $data, int $tenantId, string $path, array &$errors): void
    {
        $body = isset($data['body']) && is_string($data['body']) ? trim($data['body']) : '';
        $cannedId = $this->positiveIntOrNull($data['canned_response_id'] ?? null);
        if ($body === '' && $cannedId === null) {
            $errors[] = $this->error("$path.data", 'message_content_required', 'Nó message exige body ou canned_response_id.');
        }
        if ($cannedId !== null && ! $this->cannedBelongsToTenant($cannedId, $tenantId)) {
            $errors[] = $this->error("$path.data.canned_response_id", 'canned_out_of_tenant', 'Resposta rápida fora do Tenant.');
        }
        if (isset($data['regex']) || isset($data['webhook_url']) || isset($data['code'])) {
            $errors[] = $this->error("$path.data", 'forbidden_field', 'Campos proibidos no nó message.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{path: string, code: string, message: string}>  $errors
     */
    private function validateQuickReply(array $data, int $tenantId, string $path, array &$errors): void
    {
        $cannedId = $this->positiveIntOrNull($data['canned_response_id'] ?? null);
        if ($cannedId === null) {
            $errors[] = $this->error("$path.data.canned_response_id", 'canned_required', 'Nó quick_reply exige canned_response_id.');

            return;
        }
        if (! $this->cannedBelongsToTenant($cannedId, $tenantId)) {
            $errors[] = $this->error("$path.data.canned_response_id", 'canned_out_of_tenant', 'Resposta rápida fora do Tenant.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{path: string, code: string, message: string}>  $errors
     */
    private function validateQuestion(array $data, string $path, array &$errors): void
    {
        $prompt = isset($data['prompt']) && is_string($data['prompt']) ? trim($data['prompt']) : '';
        if ($prompt === '') {
            $errors[] = $this->error("$path.data.prompt", 'prompt_required', 'Nó question exige prompt.');
        }
        $options = $data['options'] ?? null;
        if (! is_array($options) || ! array_is_list($options) || $options === []) {
            $errors[] = $this->error("$path.data.options", 'options_required', 'Nó question exige options[] enumeradas.');

            return;
        }
        foreach ($options as $i => $option) {
            if (! is_string($option) || trim($option) === '') {
                $errors[] = $this->error("$path.data.options.$i", 'option_invalid', 'Opção inválida.');
            }
        }
        if (isset($data['regex']) || isset($data['pattern'])) {
            $errors[] = $this->error("$path.data", 'regex_forbidden', 'Regex livre não é permitido em question.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{path: string, code: string, message: string}>  $errors
     */
    private function validateCondition(array $data, string $path, array &$errors): void
    {
        $field = isset($data['field']) && is_string($data['field']) ? trim($data['field']) : '';
        $operator = isset($data['operator']) && is_string($data['operator']) ? strtolower(trim($data['operator'])) : '';
        if (! in_array($field, self::CONDITION_FIELDS, true)) {
            $errors[] = $this->error("$path.data.field", 'condition_field_forbidden', 'Campo de condição fora da allowlist.');
        }
        if (! in_array($operator, self::CONDITION_OPERATORS, true)) {
            $errors[] = $this->error("$path.data.operator", 'condition_operator_forbidden', 'Operador de condição fora da allowlist.');
        }
        if (! array_key_exists('value', $data) || (! is_string($data['value']) && ! is_int($data['value']) && ! is_bool($data['value']))) {
            $errors[] = $this->error("$path.data.value", 'condition_value_required', 'Condição exige value tipado.');
        }
        if (isset($data['regex']) || isset($data['jsonpath']) || isset($data['expression'])) {
            $errors[] = $this->error("$path.data", 'expression_forbidden', 'Expressões/regex/JSONPath livres são proibidas.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{path: string, code: string, message: string}>  $errors
     */
    private function validateDelay(array $data, string $path, array &$errors): void
    {
        $seconds = $this->positiveIntOrNull($data['duration_seconds'] ?? null);
        $max = max(1, (int) config('communication.flows.delay_max_seconds', 86_400));
        if ($seconds === null || $seconds < 1 || $seconds > $max) {
            $errors[] = $this->error("$path.data.duration_seconds", 'delay_out_of_bounds', "Delay deve estar entre 1 e {$max} segundos.");
        }
        if (isset($data['cron']) || isset($data['schedule'])) {
            $errors[] = $this->error("$path.data", 'cron_forbidden', 'Cron/schedule arbitrário não é permitido.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{path: string, code: string, message: string}>  $errors
     */
    private function validateAction(array $data, int $tenantId, string $path, array &$errors): void
    {
        $kind = isset($data['kind']) && is_string($data['kind']) ? strtolower(trim($data['kind'])) : '';
        if (! in_array($kind, self::ACTION_KINDS, true)) {
            $errors[] = $this->error("$path.data.kind", 'action_kind_forbidden', 'Ação deve ser label, assignee ou status.');

            return;
        }
        if ($kind === 'label') {
            $labelId = $this->positiveIntOrNull($data['label_id'] ?? null);
            if ($labelId === null || ! $this->labelBelongsToTenant($labelId, $tenantId)) {
                $errors[] = $this->error("$path.data.label_id", 'label_out_of_tenant', 'Label inválida para o Tenant.');
            }
        }
        if ($kind === 'assignee') {
            $membershipId = $this->positiveIntOrNull($data['assignee_membership_id'] ?? null);
            if ($membershipId === null || ! $this->membershipBelongsToTenant($membershipId, $tenantId)) {
                $errors[] = $this->error("$path.data.assignee_membership_id", 'assignee_out_of_tenant', 'Assignee fora do Tenant.');
            }
        }
        if ($kind === 'status') {
            $status = isset($data['status']) && is_string($data['status']) ? strtoupper(trim($data['status'])) : '';
            $allowed = array_map(static fn (ConversationStatus $s): string => $s->value, ConversationStatus::cases());
            if (! in_array($status, $allowed, true)) {
                $errors[] = $this->error("$path.data.status", 'status_invalid', 'Status de conversa inválido.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{path: string, code: string, message: string}>  $errors
     */
    private function validateHandoff(array $data, int $tenantId, string $path, array &$errors): void
    {
        $membershipId = $this->positiveIntOrNull($data['assignee_membership_id'] ?? null);
        if ($membershipId !== null && ! $this->membershipBelongsToTenant($membershipId, $tenantId)) {
            $errors[] = $this->error("$path.data.assignee_membership_id", 'assignee_out_of_tenant', 'Handoff assignee fora do Tenant.');
        }
        if (isset($data['webhook_url']) || isset($data['url'])) {
            $errors[] = $this->error("$path.data", 'webhook_forbidden', 'Webhook/URL é proibido em handoff.');
        }
    }

    /**
     * @param  list<array{path: string, code: string, message: string}>  $errors
     */
    private function rejectForbiddenPayload(mixed $value, string $path, array &$errors): void
    {
        if (is_string($value)) {
            $lower = strtolower($value);
            foreach (self::FORBIDDEN_TYPE_HINTS as $hint) {
                if ($lower === $hint || str_contains($lower, $hint.':') || str_contains($lower, $hint.'_')) {
                    $errors[] = $this->error($path, 'forbidden_content', "Conteúdo proibido detectado ({$hint}).");

                    return;
                }
            }

            return;
        }
        if (! is_array($value)) {
            return;
        }
        foreach ($value as $key => $child) {
            $keyStr = is_string($key) ? strtolower($key) : (string) $key;
            foreach (self::FORBIDDEN_TYPE_HINTS as $hint) {
                if ($keyStr === $hint || str_contains($keyStr, $hint)) {
                    $errors[] = $this->error($path.'.'.$keyStr, 'forbidden_field', "Campo proibido: {$keyStr}.");

                    break;
                }
            }
            $this->rejectForbiddenPayload($child, $path.(is_int($key) ? ".$key" : ".$keyStr"), $errors);
        }
    }

    /**
     * @param  array<string, list<string>>  $adjacency
     * @return array<string, true>
     */
    private function reachableFrom(string $start, array $adjacency): array
    {
        $seen = [];
        $queue = [$start];
        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === null || isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach ($adjacency[$current] ?? [] as $next) {
                $queue[] = $next;
            }
        }

        return $seen;
    }

    /**
     * @param  array<string, list<string>>  $adjacency
     * @param  array<string, true>  $subset
     */
    private function hasCycle(array $adjacency, array $subset): bool
    {
        $state = []; // 0=unseen,1=visiting,2=done
        $visit = function (string $node) use (&$visit, &$state, $adjacency, $subset): bool {
            if (! isset($subset[$node])) {
                return false;
            }
            $state[$node] = 1;
            foreach ($adjacency[$node] ?? [] as $next) {
                if (! isset($subset[$next])) {
                    continue;
                }
                if (($state[$next] ?? 0) === 1) {
                    return true;
                }
                if (($state[$next] ?? 0) === 0 && $visit($next)) {
                    return true;
                }
            }
            $state[$node] = 2;

            return false;
        };

        foreach (array_keys($subset) as $node) {
            if (($state[$node] ?? 0) === 0 && $visit($node)) {
                return true;
            }
        }

        return false;
    }

    private function cannedBelongsToTenant(int $id, int $tenantId): bool
    {
        return CommunicationCannedResponse::query()->withoutGlobalScopes()
            ->whereKey($id)
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    private function labelBelongsToTenant(int $id, int $tenantId): bool
    {
        return CommunicationLabel::query()->withoutGlobalScopes()
            ->whereKey($id)
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    private function membershipBelongsToTenant(int $id, int $tenantId): bool
    {
        return TenantMembership::query()->withoutGlobalScopes()
            ->whereKey($id)
            ->where('tenant_id', $tenantId)
            ->exists();
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * @return array{path: string, code: string, message: string}
     */
    private function error(string $path, string $code, string $message): array
    {
        return ['path' => $path, 'code' => $code, 'message' => $message];
    }
}

<?php

namespace App\Services\Work;

use App\Enums\Work\DueRuleType;
use InvalidArgumentException;

/** Catálogo determinístico de modelos-base publicados pela plataforma. */
final class ProcessTemplateCatalog
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $templates = config('work_process_catalog.templates', []);
        if (! is_array($templates)) {
            throw new InvalidArgumentException('Catálogo Work inválido.');
        }

        $normalized = [];
        foreach ($templates as $key => $definition) {
            if (! is_string($key) || ! is_array($definition)) {
                throw new InvalidArgumentException('Entrada inválida no catálogo Work.');
            }
            $normalized[$key] = $this->normalize($key, $definition);
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $key): ?array
    {
        $normalizedKey = mb_strtoupper(trim($key));

        return $this->all()[$normalizedKey] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function findOrFail(string $key): array
    {
        return $this->find($key)
            ?? throw new InvalidArgumentException('Modelo-base inexistente no catálogo Work.');
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function normalize(string $key, array $definition): array
    {
        $version = (int) ($definition['version'] ?? 0);
        $name = trim((string) ($definition['name'] ?? ''));
        $tasks = $definition['tasks'] ?? [];
        if ($version < 1 || $name === '' || ! is_array($tasks) || $tasks === []) {
            throw new InvalidArgumentException("Modelo-base {$key} está incompleto.");
        }

        $taskDefinitions = [];
        foreach (array_values($tasks) as $index => $task) {
            if (! is_array($task) || trim((string) ($task['title'] ?? '')) === '') {
                throw new InvalidArgumentException("Tarefa inválida no modelo-base {$key}.");
            }
            $taskDefinitions[] = [
                'sort_order' => $index + 1,
                'title' => trim((string) $task['title']),
                'description' => isset($task['description']) ? trim((string) $task['description']) : null,
                'due_rule_type' => DueRuleType::DaysBeforeProcessDue->value,
                'due_rule_value' => max(0, (int) ($task['days_before_due'] ?? 0)),
                'is_required' => (bool) ($task['is_required'] ?? true),
                'is_critical' => (bool) ($task['is_critical'] ?? false),
                'requires_evidence' => (bool) ($task['requires_evidence'] ?? false),
            ];
        }

        return [
            'key' => $key,
            'version' => $version,
            'name' => $name,
            'description' => trim((string) ($definition['description'] ?? '')) ?: null,
            'department_role' => trim((string) ($definition['department_role'] ?? '')) ?: null,
            'monitoring_module_key' => $definition['monitoring_module_key'] ?? null,
            'default_due_rule_type' => (string) ($definition['default_due_rule_type'] ?? ''),
            'default_due_rule_value' => (int) ($definition['default_due_rule_value'] ?? 0),
            'audience_rules' => is_array($definition['audience_rules'] ?? null)
                ? $definition['audience_rules']
                : [],
            'tasks' => $taskDefinitions,
        ];
    }
}

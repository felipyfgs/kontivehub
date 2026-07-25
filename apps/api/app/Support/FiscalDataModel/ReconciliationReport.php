<?php

namespace App\Support\FiscalDataModel;

/**
 * Relatório de reconciliação baseline × modelo-alvo (sanitizado).
 */
final class ReconciliationReport
{
    /**
     * @param  list<array{aggregate: string, metric: string, expected: mixed, actual: mixed, severity: string}>  $divergences
     * @param  list<array{aggregate: string, metric: string, expected: mixed, actual: mixed}>  $matches
     */
    public function __construct(
        public readonly bool $passed,
        public readonly array $divergences,
        public readonly array $matches,
        public readonly string $generatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'generated_at' => $this->generatedAt,
            'matches_count' => count($this->matches),
            'divergences_count' => count($this->divergences),
            'matches' => $this->matches,
            'divergences' => $this->divergences,
        ];
    }
}

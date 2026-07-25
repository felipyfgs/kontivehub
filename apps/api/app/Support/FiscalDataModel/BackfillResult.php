<?php

namespace App\Support\FiscalDataModel;

/**
 * Resultado sanitizado de um backfill (sem payloads sensíveis).
 */
final class BackfillResult
{
    /**
     * @param  list<array{source_table: string, source_id: int|string, reason: string}>  $rejections
     * @param  list<array{source_table: string, source_id: int|string, reason: string}>  $ambiguities
     */
    public function __construct(
        public readonly string $aggregate,
        public readonly bool $dryRun,
        public readonly int $processed,
        public readonly int $mapped,
        public readonly int $skipped,
        public readonly int $rejected,
        public readonly int $ambiguous,
        public readonly array $rejections = [],
        public readonly array $ambiguities = [],
        public readonly ?string $checkpoint = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'aggregate' => $this->aggregate,
            'dry_run' => $this->dryRun,
            'processed' => $this->processed,
            'mapped' => $this->mapped,
            'skipped' => $this->skipped,
            'rejected' => $this->rejected,
            'ambiguous' => $this->ambiguous,
            'checkpoint' => $this->checkpoint,
            'rejections' => $this->rejections,
            'ambiguities' => $this->ambiguities,
        ];
    }
}

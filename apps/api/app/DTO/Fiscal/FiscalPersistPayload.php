<?php

namespace App\DTO\Fiscal;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Models\FiscalMonitoringRun;

/**
 * Payload para persistência atômica: evidência + snapshot antes de projeções.
 */
final readonly class FiscalPersistPayload
{
    /**
     * @param  list<array{code:string,severity?:string,title:string,detail?:string,situation?:string,creates_pending?:bool,due_at?:string|null}>  $findings
     * @param  array<string, mixed>|null  $normalized
     * @param  array<string, mixed>  $progress
     */
    public function __construct(
        public FiscalMonitoringRun $run,
        public FiscalRunResult $result,
        public FiscalSituation $situation,
        public FiscalCoverage $coverage,
        public ?string $evidenceBytes = null,
        public string $evidenceContentType = 'application/json',
        public string $evidenceSource = 'adapter',
        public ?string $sourceVersion = null,
        public ?array $normalized = null,
        public array $findings = [],
        public bool $shouldRequeue = false,
        public ?string $progressCursor = null,
        public array $progress = [],
        public int $itemsProcessed = 0,
        public int $pagesProcessed = 0,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?string $skipReason = null,
        public ?int $requeueAfterSeconds = null,
    ) {}

    public static function fromAdapterResult(FiscalMonitoringRun $run, FiscalAdapterResult $result, string $source = 'adapter'): self
    {
        return new self(
            run: $run,
            result: $result->result,
            situation: $result->situation,
            coverage: $result->coverage,
            evidenceBytes: $result->evidenceBytes,
            evidenceContentType: $result->evidenceContentType,
            evidenceSource: $source,
            sourceVersion: $result->sourceVersion,
            normalized: $result->normalized,
            findings: $result->findings,
            shouldRequeue: $result->shouldRequeue,
            progressCursor: $result->progressCursor,
            progress: $result->progress,
            itemsProcessed: $result->itemsProcessed,
            pagesProcessed: $result->pagesProcessed,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            skipReason: $result->skipReason,
            requeueAfterSeconds: $result->requeueAfterSeconds,
        );
    }
}

<?php

namespace App\DTO\Fiscal;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;

/**
 * Resultado normalizável de um adapter (antes de projeção).
 * Situação UP_TO_DATE só é aceita com evidenceBytes e coverage Full.
 */
final readonly class FiscalAdapterResult
{
    /**
     * @param  list<array{code:string,severity?:string,title:string,detail?:string,situation?:string,creates_pending?:bool,due_at?:string|null}>  $findings
     * @param  array<string, mixed>|null  $normalized
     * @param  array<string, mixed>  $progress
     */
    public function __construct(
        public FiscalRunResult $result,
        public FiscalSituation $situation,
        public FiscalCoverage $coverage,
        public ?string $evidenceBytes = null,
        public string $evidenceContentType = 'application/json',
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
        /** Segundos até a continuação (polling respeitoso / espera SITFIS). */
        public ?int $requeueAfterSeconds = null,
    ) {}

    public static function blocked(string $reason, string $code = 'BLOCKED'): self
    {
        return new self(
            result: FiscalRunResult::Blocked,
            situation: FiscalSituation::Blocked,
            coverage: FiscalCoverage::Unknown,
            skipReason: $code,
            errorCode: $code,
            errorMessage: $reason,
        );
    }

    public static function skipped(string $reason, string $code = 'SKIPPED'): self
    {
        return new self(
            result: FiscalRunResult::Skipped,
            situation: FiscalSituation::Unknown,
            coverage: FiscalCoverage::Unknown,
            skipReason: $reason,
            errorCode: $code,
        );
    }

    public static function unsupported(string $explanation): self
    {
        return new self(
            result: FiscalRunResult::Success,
            situation: FiscalSituation::Unsupported,
            coverage: FiscalCoverage::Unsupported,
            evidenceBytes: json_encode(['unsupported' => true, 'explanation' => $explanation], JSON_THROW_ON_ERROR),
            normalized: [
                'situation' => FiscalSituation::Unsupported->value,
                'coverage' => FiscalCoverage::Unsupported->value,
                'explanation' => $explanation,
            ],
            findings: [[
                'code' => 'COVERAGE_UNSUPPORTED',
                'severity' => FiscalFindingSeverity::Info->value,
                'title' => 'Fonte não suportada',
                'detail' => $explanation,
                'situation' => FiscalSituation::Unsupported->value,
                'creates_pending' => false,
            ]],
        );
    }

    public static function failed(string $message, string $code = 'ADAPTER_FAILED', ?FiscalCoverage $coverage = null): self
    {
        return new self(
            result: FiscalRunResult::Failed,
            situation: FiscalSituation::Error,
            coverage: $coverage ?? FiscalCoverage::Unknown,
            errorCode: $code,
            errorMessage: $message,
        );
    }
}

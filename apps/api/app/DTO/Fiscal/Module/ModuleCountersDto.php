<?php

namespace App\DTO\Fiscal\Module;

use App\Enums\FiscalSituation;

/**
 * Contadores de KPI no mesmo escopo filtrado da carteira (não só da página).
 * Partição completa das nove situações canônicas; soma = total_clients do overview.
 */
final readonly class ModuleCountersDto
{
    public function __construct(
        public int $upToDate = 0,
        public int $processing = 0,
        public int $pending = 0,
        public int $attention = 0,
        public int $error = 0,
        public int $blocked = 0,
        public int $unknown = 0,
        public int $unsupported = 0,
        public int $notApplicable = 0,
    ) {}

    /**
     * @param  array<string, int>  $map  chaves = FiscalSituation::value
     */
    public static function fromSituationMap(array $map): self
    {
        return new self(
            upToDate: (int) ($map[FiscalSituation::UpToDate->value] ?? 0),
            processing: (int) ($map[FiscalSituation::Processing->value] ?? 0),
            pending: (int) ($map[FiscalSituation::Pending->value] ?? 0),
            attention: (int) ($map[FiscalSituation::Attention->value] ?? 0),
            error: (int) ($map[FiscalSituation::Error->value] ?? 0),
            blocked: (int) ($map[FiscalSituation::Blocked->value] ?? 0),
            unknown: (int) ($map[FiscalSituation::Unknown->value] ?? 0),
            unsupported: (int) ($map[FiscalSituation::Unsupported->value] ?? 0),
            notApplicable: (int) ($map[FiscalSituation::NotApplicable->value] ?? 0),
        );
    }

    /** Soma das nove chaves (partição completa). */
    public function total(): int
    {
        return $this->upToDate
            + $this->processing
            + $this->pending
            + $this->attention
            + $this->error
            + $this->blocked
            + $this->unknown
            + $this->unsupported
            + $this->notApplicable;
    }

    /**
     * @return array{
     *     up_to_date: int,
     *     processing: int,
     *     pending: int,
     *     attention: int,
     *     error: int,
     *     blocked: int,
     *     unknown: int,
     *     unsupported: int,
     *     not_applicable: int
     * }
     */
    public function toArray(): array
    {
        return [
            'up_to_date' => $this->upToDate,
            'processing' => $this->processing,
            'pending' => $this->pending,
            'attention' => $this->attention,
            'error' => $this->error,
            'blocked' => $this->blocked,
            'unknown' => $this->unknown,
            'unsupported' => $this->unsupported,
            'not_applicable' => $this->notApplicable,
        ];
    }
}

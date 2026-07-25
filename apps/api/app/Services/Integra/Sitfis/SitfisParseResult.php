<?php

namespace App\Services\Integra\Sitfis;

use App\Enums\FiscalSituation;

/**
 * Resultado do parser versionado do relatório SITFIS.
 *
 * @phpstan-type FindingRow array{
 *     code: string,
 *     severity?: string,
 *     title: string,
 *     detail?: string|null,
 *     situation?: string,
 *     creates_pending?: bool,
 *     due_at?: string|null
 * }
 */
final class SitfisParseResult
{
    /**
     * @param  list<FindingRow>  $findings
     * @param  list<string>  $unknownSections
     * @param  array<string, mixed>  $normalized
     */
    public function __construct(
        public readonly string $parserVersion,
        public readonly bool $layoutRecognized,
        public readonly bool $contractChanged,
        public readonly FiscalSituation $situation,
        public readonly array $findings,
        public readonly array $unknownSections = [],
        public readonly array $normalized = [],
        public readonly bool $claimsNegativeCertificate = false,
    ) {}
}

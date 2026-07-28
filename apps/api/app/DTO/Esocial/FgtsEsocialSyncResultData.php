<?php

namespace App\DTO\Esocial;

use App\Models\EsocialEventEvidence;
use App\Models\FgtsCompetenceStatus;

final readonly class FgtsEsocialSyncResultData
{
    /**
     * @param  list<EsocialEventEvidence>  $evidences
     * @param  array<string, mixed>  $coverage
     */
    public function __construct(
        public FgtsCompetenceStatus $status,
        public FgtsCompetenceProjection $projection,
        public int $eventsCount,
        public array $evidences,
        public array $coverage,
    ) {}
}

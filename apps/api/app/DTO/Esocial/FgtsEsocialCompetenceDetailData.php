<?php

namespace App\DTO\Esocial;

use App\Models\EsocialEventEvidence;
use App\Models\FgtsCompetenceStatus;
use Illuminate\Support\Collection;

final readonly class FgtsEsocialCompetenceDetailData
{
    /**
     * @param  Collection<int, EsocialEventEvidence>  $events
     * @param  array<string, mixed>  $coverage
     */
    public function __construct(
        public FgtsCompetenceStatus $status,
        public Collection $events,
        public array $coverage,
    ) {}
}

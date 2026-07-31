<?php

namespace App\DTO\Communication;

final readonly class FlowDraftData
{
    /** @param array{nodes: list<mixed>, edges: list<mixed>} $graph */
    public function __construct(
        public array $graph,
        public int $lockVersion,
    ) {}
}

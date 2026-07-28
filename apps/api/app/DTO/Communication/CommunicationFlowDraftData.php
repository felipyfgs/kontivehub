<?php

namespace App\DTO\Communication;

final readonly class CommunicationFlowDraftData
{
    /** @param array{nodes: list<mixed>, edges: list<mixed>} $graph */
    public function __construct(
        public array $graph,
        public int $lockVersion,
    ) {}
}

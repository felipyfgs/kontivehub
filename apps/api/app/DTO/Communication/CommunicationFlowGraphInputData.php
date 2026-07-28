<?php

namespace App\DTO\Communication;

final readonly class CommunicationFlowGraphInputData
{
    /**
     * @param  array{nodes: list<mixed>, edges: list<mixed>}|null  $graph
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public ?array $graph,
        public array $context = [],
    ) {}
}

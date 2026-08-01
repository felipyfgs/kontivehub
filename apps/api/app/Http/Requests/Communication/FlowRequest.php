<?php

namespace App\Http\Requests\Communication;

use App\Models\User;
use App\Services\Communication\Authorization\Access;

abstract class FlowRequest extends TenantScopedRequest
{
    protected function canViewFlow(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(Access::class)->canViewFlows(
                $actor,
                $this->routeTarget(),
            );
    }

    protected function canManageFlow(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(Access::class)->canManageFlows(
                $actor,
                $this->routeTarget(),
            );
    }

    /** @return array<string, list<mixed>> */
    protected function graphRules(string $presence): array
    {
        return [
            'graph' => [$presence, 'array'],
            'graph.nodes' => ['required_with:graph', 'array'],
            'graph.nodes.*' => ['nullable', 'array'],
            'graph.nodes.*.id' => ['nullable'],
            'graph.nodes.*.type' => ['nullable'],
            'graph.nodes.*.label' => ['nullable'],
            'graph.nodes.*.position' => ['nullable', 'array'],
            'graph.nodes.*.position.x' => ['nullable'],
            'graph.nodes.*.position.y' => ['nullable'],
            'graph.nodes.*.data' => ['nullable', 'array'],
            'graph.nodes.*.data.*' => ['nullable'],
            'graph.nodes.*.data.options.*' => ['nullable'],
            'graph.edges' => ['required_with:graph', 'array'],
            'graph.edges.*' => ['nullable', 'array'],
            'graph.edges.*.id' => ['nullable'],
            'graph.edges.*.source' => ['nullable'],
            'graph.edges.*.target' => ['nullable'],
            'graph.edges.*.label' => ['nullable'],
            'graph.edges.*.branch' => ['nullable'],
            'graph.edges.*.data' => ['nullable', 'array'],
            'graph.edges.*.data.*' => ['nullable'],
        ];
    }

    private function routeTarget(): mixed
    {
        return $this->route('binding')
            ?? $this->route('flow');
    }
}

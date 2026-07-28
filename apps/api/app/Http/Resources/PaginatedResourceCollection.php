<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Arr;

abstract class PaginatedResourceCollection extends ResourceCollection
{
    /**
     * Customize pagination information without changing the item envelope.
     *
     * @param  array<string, mixed>  $paginated
     * @param  array{links: array<string, mixed>, meta: array<string, mixed>}  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        $metaFields = $this->paginationMetaFields();
        $information = [
            'meta' => $metaFields === null
                ? $default['meta']
                : Arr::only($default['meta'], $metaFields),
        ];

        if ($this->includesPaginationLinks()) {
            $information = ['links' => $default['links']] + $information;
        }

        return $information;
    }

    /** @return list<string>|null */
    protected function paginationMetaFields(): ?array
    {
        return null;
    }

    protected function includesPaginationLinks(): bool
    {
        return true;
    }
}

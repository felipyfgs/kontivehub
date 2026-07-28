<?php

namespace App\Http\Requests\Fiscal\Monitoring;

final class ListSimplesMeiRegimeResolutionsRequest extends ViewSimplesMeiClientRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
        ];
    }

    public function year(): ?int
    {
        $year = $this->validated('year');

        return $year !== null ? (int) $year : null;
    }
}

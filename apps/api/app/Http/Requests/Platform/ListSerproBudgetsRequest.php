<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\SerproBudgetFilterData;
use App\Http\Requests\AuthenticatedRequest;
use Illuminate\Validation\Rule;

final class ListSerproBudgetsRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        $query = $this->query->all();
        if (isset($query['scope']) && is_string($query['scope'])) {
            $query['scope'] = strtoupper($query['scope']);
        }

        $this->merge($query);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'scope' => ['sometimes', 'string', Rule::in(['GLOBAL', 'TENANT', 'OPERATION'])],
        ];
    }

    public function toDto(): SerproBudgetFilterData
    {
        $scope = $this->validated('scope');

        return new SerproBudgetFilterData(
            scope: is_string($scope) ? $scope : null,
        );
    }
}

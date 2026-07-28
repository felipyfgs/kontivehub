<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\SerproEnvironmentFilterData;
use App\Enums\SerproEnvironment;
use App\Http\Requests\AuthenticatedRequest;
use Illuminate\Validation\Rule;

final class FilterSerproEnvironmentRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        $query = $this->query->all();
        if (isset($query['environment']) && is_string($query['environment'])) {
            $query['environment'] = strtoupper($query['environment']);
        }

        $this->merge($query);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
        ];
    }

    public function toDto(): SerproEnvironmentFilterData
    {
        $environment = $this->validated('environment');

        return new SerproEnvironmentFilterData(
            environment: is_string($environment) ? SerproEnvironment::from($environment) : null,
        );
    }
}

<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\SerproReadinessFilterData;
use App\Enums\SerproEnvironment;
use App\Http\Requests\AuthenticatedRequest;
use Illuminate\Validation\Rule;

final class GetSerproReadinessRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        $query = $this->query->all();

        if (isset($query['environment']) && is_string($query['environment'])) {
            $query['environment'] = strtoupper($query['environment']);
        }

        if (isset($query['persist']) && is_string($query['persist'])) {
            $normalized = strtolower($query['persist']);
            if (in_array($normalized, ['1', 'true'], true)) {
                $query['persist'] = true;
            } elseif (in_array($normalized, ['0', 'false'], true)) {
                $query['persist'] = false;
            }
        }

        $this->merge($query);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'persist' => ['sometimes', 'boolean'],
        ];
    }

    public function toDto(): SerproReadinessFilterData
    {
        $environment = $this->validated('environment');

        return new SerproReadinessFilterData(
            environment: is_string($environment) ? SerproEnvironment::from($environment) : null,
            persist: (bool) $this->validated('persist', true),
        );
    }
}

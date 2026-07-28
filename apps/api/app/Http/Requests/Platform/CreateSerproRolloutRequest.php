<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\SerproRolloutCreationData;
use App\Enums\SerproEnvironment;
use App\Http\Requests\AuthenticatedRequest;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

final class CreateSerproRolloutRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        $environment = $this->input('environment');
        if (is_string($environment)) {
            $this->merge(['environment' => strtoupper($environment)]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'max:40'],
            'subject_type' => ['required', 'string', 'max:40'],
            'subject_id' => ['nullable', 'integer'],
            'reason' => ['required', 'string', 'max:500'],
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'tenant_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'context' => ['sometimes', 'array'],
            'context.*' => ['nullable'],
            'ttl_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'change_window_start' => ['sometimes', 'nullable', 'date'],
            'change_window_end' => ['sometimes', 'nullable', 'date', 'after:change_window_start'],
        ];
    }

    public function toDto(): SerproRolloutCreationData
    {
        $data = $this->validated();

        return new SerproRolloutCreationData(
            action: $data['action'],
            subjectType: $data['subject_type'],
            subjectId: isset($data['subject_id']) ? (int) $data['subject_id'] : null,
            reason: $data['reason'],
            environment: isset($data['environment'])
                ? SerproEnvironment::from($data['environment'])
                : null,
            tenantId: isset($data['tenant_id']) ? (int) $data['tenant_id'] : null,
            context: $data['context'] ?? [],
            ttlHours: (int) ($data['ttl_hours'] ?? 24),
            changeWindowStart: isset($data['change_window_start'])
                ? CarbonImmutable::parse($data['change_window_start'])
                : null,
            changeWindowEnd: isset($data['change_window_end'])
                ? CarbonImmutable::parse($data['change_window_end'])
                : null,
        );
    }
}

<?php

namespace App\Http\Requests\Sefaz;

use App\Http\Requests\AuthenticatedRequest;

final class ListCteCoverageRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'period' => ['nullable', 'date_format:Y-m'],
            'client_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
        ];
    }

    public function period(): string
    {
        $value = $this->validated('period');

        return is_string($value) && $value !== ''
            ? $value
            : now()->format('Y-m');
    }

    public function clientId(): ?int
    {
        $value = $this->validated('client_id');

        return $value !== null ? (int) $value : null;
    }

    public function status(): ?string
    {
        $value = $this->validated('status');

        return is_string($value) ? $value : null;
    }
}

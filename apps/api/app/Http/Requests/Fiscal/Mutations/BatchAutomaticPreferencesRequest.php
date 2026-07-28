<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Http\Requests\AuthenticatedRequest;

final class BatchAutomaticPreferencesRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_ids' => ['required', 'array', 'min:1', 'max:100'],
            'client_ids.*' => ['integer', 'distinct'],
            'automatic_requested' => ['required', 'boolean'],
        ];
    }

    /** @return list<int> */
    public function clientIds(): array
    {
        return array_map('intval', $this->validated('client_ids') ?? []);
    }

    public function automaticRequested(): bool
    {
        return (bool) $this->validated('automatic_requested');
    }
}

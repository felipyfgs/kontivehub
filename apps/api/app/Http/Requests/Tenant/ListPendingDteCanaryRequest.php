<?php

namespace App\Http\Requests\Tenant;

use App\Http\Requests\AuthenticatedRequest;

final class ListPendingDteCanaryRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge($this->query->all());
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}

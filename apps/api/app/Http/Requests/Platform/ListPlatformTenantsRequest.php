<?php

namespace App\Http\Requests\Platform;

use App\Enums\SubscriptionStatus;
use App\Http\Requests\AuthenticatedRequest;
use Illuminate\Validation\Rule;

final class ListPlatformTenantsRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        $query = $this->query->all();
        if (isset($query['status']) && is_string($query['status'])) {
            $query['status'] = strtoupper($query['status']);
        }

        $this->merge($query);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(SubscriptionStatus::class)],
        ];
    }

    public function status(): ?SubscriptionStatus
    {
        $status = $this->validated('status');

        return is_string($status) ? SubscriptionStatus::from($status) : null;
    }
}

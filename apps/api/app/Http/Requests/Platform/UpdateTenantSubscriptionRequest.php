<?php

namespace App\Http\Requests\Platform;

use App\DTO\Platform\TenantSubscriptionUpdateData;
use App\Enums\SubscriptionPlan;
use App\Enums\SubscriptionStatus;
use App\Http\Requests\AuthenticatedRequest;
use Illuminate\Validation\Rule;

final class UpdateTenantSubscriptionRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(SubscriptionStatus::class)],
            'plan' => ['sometimes', 'string', Rule::enum(SubscriptionPlan::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'negotiated_client_limit' => ['sometimes', 'nullable', 'integer', 'min:201', 'max:100000'],
        ];
    }

    public function toDto(): TenantSubscriptionUpdateData
    {
        $validated = $this->validated();

        return new TenantSubscriptionUpdateData(
            hasStatus: array_key_exists('status', $validated),
            status: isset($validated['status'])
                ? SubscriptionStatus::from((string) $validated['status'])
                : null,
            hasPlan: array_key_exists('plan', $validated),
            plan: isset($validated['plan'])
                ? SubscriptionPlan::from((string) $validated['plan'])
                : null,
            hasNotes: array_key_exists('notes', $validated),
            notes: isset($validated['notes']) ? (string) $validated['notes'] : null,
            hasNegotiatedClientLimit: array_key_exists('negotiated_client_limit', $validated),
            negotiatedClientLimit: isset($validated['negotiated_client_limit'])
                ? (int) $validated['negotiated_client_limit']
                : null,
        );
    }
}

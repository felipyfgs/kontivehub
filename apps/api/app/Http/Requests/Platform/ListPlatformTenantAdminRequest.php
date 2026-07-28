<?php

namespace App\Http\Requests\Platform;

use App\DTO\Platform\TenantLifecycleFilterData;
use App\Enums\TenantLifecycleStatus;
use App\Http\Requests\AuthenticatedRequest;
use Illuminate\Validation\Rule;

final class ListPlatformTenantAdminRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        $query = $this->query->all();
        if (array_key_exists('lifecycle_status', $query)) {
            $status = $query['lifecycle_status'];
            if ($status === null || (is_string($status) && trim($status) === '')) {
                $query['lifecycle_status'] = 'ALL';
            } elseif (is_string($status)) {
                $query['lifecycle_status'] = strtoupper(trim($status));
            }
        }

        $this->merge($query);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'lifecycle_status' => [
                'sometimes',
                'string',
                Rule::in([
                    'ALL',
                    ...array_map(
                        static fn (TenantLifecycleStatus $status): string => $status->value,
                        TenantLifecycleStatus::cases(),
                    ),
                ]),
            ],
        ];
    }

    public function toDto(): TenantLifecycleFilterData
    {
        $status = $this->validated('lifecycle_status');

        return new TenantLifecycleFilterData(
            status: is_string($status) && $status !== 'ALL'
                ? TenantLifecycleStatus::from($status)
                : null,
        );
    }
}

<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\SerproRolloutFilterData;
use App\Http\Requests\AuthenticatedRequest;
use Illuminate\Validation\Rule;

final class ListSerproRolloutsRequest extends AuthenticatedRequest
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
            'status' => [
                'sometimes',
                'string',
                Rule::in(['PENDING', 'PARTIAL', 'APPROVED', 'EXECUTED', 'REJECTED', 'EXPIRED']),
            ],
        ];
    }

    public function toDto(): SerproRolloutFilterData
    {
        $status = $this->validated('status');

        return new SerproRolloutFilterData(
            status: is_string($status) ? $status : null,
        );
    }
}

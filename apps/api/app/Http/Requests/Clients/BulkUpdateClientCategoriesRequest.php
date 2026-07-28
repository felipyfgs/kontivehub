<?php

namespace App\Http\Requests\Clients;

use App\DTO\Clients\BulkClientCategoryUpdateData;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Policies\ClientPolicy;
use Illuminate\Validation\Rule;

final class BulkUpdateClientCategoriesRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(ClientPolicy::class)->create($actor);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operation' => ['required', 'string', Rule::in(['add', 'remove'])],
            'client_ids' => ['required', 'array', 'min:1', 'max:100'],
            'client_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'category_ids' => ['required', 'array', 'min:1', 'max:25'],
            'category_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function toDto(): BulkClientCategoryUpdateData
    {
        $data = $this->validated();

        return new BulkClientCategoryUpdateData(
            operation: (string) $data['operation'],
            clientIds: array_values(array_map('intval', $data['client_ids'])),
            categoryIds: array_values(array_map('intval', $data['category_ids'])),
            actor: $this->actor(),
        );
    }
}

<?php

namespace App\Http\Requests\Clients;

use App\DTO\Clients\ClientCategoryListFilterData;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Policies\ClientCategoryPolicy;

final class ListClientCategoriesRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(ClientCategoryPolicy::class)->viewAny($actor);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'include_archived' => ['nullable', 'boolean'],
        ];
    }

    public function toDto(): ClientCategoryListFilterData
    {
        return new ClientCategoryListFilterData(
            includeArchived: $this->boolean('include_archived'),
        );
    }
}

<?php

namespace App\Http\Requests\Clients;

use App\DTO\Clients\ClientCategoryReplacementData;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\Client;
use App\Models\User;
use App\Policies\ClientPolicy;

final class ReplaceClientCategoriesRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $client = $this->route('client');

        return $actor instanceof User
            && $client instanceof Client
            && app(ClientPolicy::class)->update($actor, $client);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_ids' => ['present', 'array', 'max:25'],
            'category_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'tenant_id' => ['prohibited'],
            'client_id' => ['prohibited'],
        ];
    }

    public function toDto(): ClientCategoryReplacementData
    {
        return new ClientCategoryReplacementData(
            categoryIds: array_values(array_map(
                'intval',
                $this->validated('category_ids'),
            )),
            actorId: (int) $this->actor()->id,
        );
    }
}

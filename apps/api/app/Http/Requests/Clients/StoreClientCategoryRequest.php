<?php

namespace App\Http\Requests\Clients;

use App\DTO\Clients\ClientCategoryCreationData;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\ClientCategory;
use App\Models\User;
use App\Policies\ClientCategoryPolicy;
use App\Support\CurrentTenant;
use Illuminate\Validation\Rule;

final class StoreClientCategoryRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(ClientCategoryPolicy::class)->create($actor);
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('name')) {
            return;
        }

        $name = preg_replace('/\s+/u', ' ', trim((string) $this->input('name')))
            ?? trim((string) $this->input('name'));

        $this->merge([
            'name' => $name,
            '_name_key' => ClientCategory::normalizeNameKey($name),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'name' => ['required', 'string', 'max:80'],
            '_name_key' => [
                'required',
                'string',
                'max:80',
                Rule::unique('client_categories', 'name_key')->where('tenant_id', $tenantId),
            ],
            'color' => ['required', 'string', Rule::in(ClientCategory::COLORS)],
            'tenant_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'is_active' => ['prohibited'],
        ];
    }

    public function toDto(): ClientCategoryCreationData
    {
        $data = $this->validated();

        return new ClientCategoryCreationData(
            name: (string) $data['name'],
            nameKey: (string) $data['_name_key'],
            color: (string) $data['color'],
            actorId: (int) $this->actor()->id,
        );
    }
}

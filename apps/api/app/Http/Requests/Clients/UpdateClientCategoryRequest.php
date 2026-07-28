<?php

namespace App\Http\Requests\Clients;

use App\DTO\Clients\ClientCategoryUpdateData;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\ClientCategory;
use App\Models\User;
use App\Policies\ClientCategoryPolicy;
use App\Support\CurrentTenant;
use Illuminate\Validation\Rule;

final class UpdateClientCategoryRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $category = $this->route('clientCategory');

        return $actor instanceof User
            && $category instanceof ClientCategory
            && app(ClientCategoryPolicy::class)->update($actor, $category);
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
        /** @var ClientCategory|null $category */
        $category = $this->route('clientCategory');
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            '_name_key' => [
                'required_with:name',
                'string',
                'max:80',
                Rule::unique('client_categories', 'name_key')
                    ->where('tenant_id', $tenantId)
                    ->ignore($category?->id),
            ],
            'color' => ['sometimes', 'required', 'string', Rule::in(ClientCategory::COLORS)],
            'is_active' => ['sometimes', 'boolean'],
            'tenant_id' => ['prohibited'],
            'created_by' => ['prohibited'],
        ];
    }

    public function toDto(): ClientCategoryUpdateData
    {
        return new ClientCategoryUpdateData($this->validated());
    }
}

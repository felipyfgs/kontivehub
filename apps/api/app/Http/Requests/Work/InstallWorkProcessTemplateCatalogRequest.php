<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\ProcessTemplateCatalogInstallationData;
use App\Models\User;
use App\Models\WorkProcessTemplate;

final class InstallWorkProcessTemplateCatalogRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->can('create', WorkProcessTemplate::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:160'],
            'default_department_id' => ['sometimes', 'nullable', 'integer'],
        ];
    }

    public function payload(): ProcessTemplateCatalogInstallationData
    {
        $validated = $this->validated();

        return new ProcessTemplateCatalogInstallationData(
            name: $validated['name'] ?? null,
            defaultDepartmentId: $validated['default_department_id'] ?? null,
        );
    }
}

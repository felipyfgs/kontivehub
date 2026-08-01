<?php

namespace App\Http\Requests\Work;

use App\Models\User;
use App\Models\WorkProcessTemplate;

final class ListProcessTemplateCatalogRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->can('viewAny', WorkProcessTemplate::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}

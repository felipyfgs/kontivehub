<?php

namespace App\Http\Requests\Work;

use App\Models\User;
use App\Models\WorkTask;

final class ViewKpisRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->can('viewAny', WorkTask::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}

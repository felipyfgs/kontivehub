<?php

namespace App\Http\Requests\Work;

use App\Models\User;
use App\Models\WorkProcess;

final class ViewProcessRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $process = $this->route('process');

        return $actor instanceof User
            && $process instanceof WorkProcess
            && $actor->can('view', $process);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}

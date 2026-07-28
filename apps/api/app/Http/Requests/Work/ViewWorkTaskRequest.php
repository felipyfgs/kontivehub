<?php

namespace App\Http\Requests\Work;

use App\Models\User;
use App\Models\WorkTask;

final class ViewWorkTaskRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $task = $this->route('task');

        return $actor instanceof User
            && $task instanceof WorkTask
            && $actor->can('view', $task);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}

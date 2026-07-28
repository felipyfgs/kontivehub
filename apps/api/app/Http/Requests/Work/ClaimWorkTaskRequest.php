<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\WorkTaskTransitionData;
use App\Models\User;
use App\Models\WorkTask;

final class ClaimWorkTaskRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $task = $this->route('task');

        return $actor instanceof User
            && $task instanceof WorkTask
            && $actor->can('claim', $task);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['lock_version' => ['required', 'integer', 'min:1']];
    }

    public function transition(): WorkTaskTransitionData
    {
        return new WorkTaskTransitionData(
            lockVersion: $this->integer('lock_version'),
        );
    }
}
